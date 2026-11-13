<?php

namespace Tests\Feature\Api;

use App\Models\Customer;
use App\Models\Project;
use App\Models\ProjectUnit;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * «مشاريعي» as the app will do it with a server: a project — once however often sent — its wording and his
 * decision, his units and theirs, publishing, an edit, a unit edited and removed, pause and resume, and the
 * brokers of its city by their matching clients' count, none of the clients shown. Its answers are the app's
 * recorded contract.
 */
class MyProjectsTest extends TestCase
{
    private function aiCleans(): void
    {
        Http::fake(function (Request $request) {
            $usage = ['usage' => ['tokens_in' => 10, 'tokens_out' => 10], 'latency_ms' => 100, 'degraded' => false];

            return match (true) {
                str_ends_with($request->url(), '/agents/clean_project') => Http::response(['output' => ['desc_clean' => "{$request['text']} — مصقول."]] + $usage),
                str_ends_with($request->url(), '/agents/clean_units') => Http::response(['output' => ['units' => array_map(
                    fn (array $u) => ['id' => $u['id'], 'desc_clean' => "{$u['text']} — مصقول."], $request['units'])]] + $usage),
                default => null,
            };
        });
    }

    /** @return array<string, mixed> */
    private function project(): array
    {
        return ['title' => 'مشروع الأفق', 'city' => 'الرياض', 'neighborhood' => 'النرجس', 'property_type' => 'villa', 'delivery' => 'offplan',
            'total_units' => 20, 'description' => 'فلل حديثة بتشطيب فاخر وحدائق خاصة'];
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function unit(string $model, array $extra = []): array
    {
        return $extra + ['model_name' => $model, 'property_type' => 'villa', 'area_built' => 380, 'bedrooms' => 5, 'bathrooms' => 5, 'halls' => 2,
            'price' => 1950000, 'available_count' => 6, 'description' => "نموذج {$model} بخمس غرف ومجلس مستقل"];
    }

    public function test_his_project_its_wording_units_publishing_and_edits(): void
    {
        $this->aiCleans();
        $developer = $this->signIn(Customer::factory()->developer()->create());

        $created = $this->postJson('/api/v1/projects', $this->project(), ['Idempotency-Key' => 'prj-0001-abcdef'])->assertCreated();
        $this->postJson('/api/v1/projects', $this->project(), ['Idempotency-Key' => 'prj-0001-abcdef'])->assertCreated()->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame(1, Project::query()->count(), 'sent twice, added once');
        $this->apiContract('project_created', $created);
        $id = $created->json('data.id');

        $first = $this->postJson("/api/v1/projects/{$id}/units", $this->unit('C1'), ['Idempotency-Key' => 'unit-0001-abcdef'])->assertCreated();
        $this->postJson("/api/v1/projects/{$id}/units", $this->unit('C1'), ['Idempotency-Key' => 'unit-0001-abcdef'])->assertCreated()->assertHeader('Idempotent-Replayed', 'true');
        $this->apiContract('project_unit_created', $first);
        $second = $this->postJson("/api/v1/projects/{$id}/units", $this->unit('D2', ['price' => 2400000, 'available_count' => 3]))->assertCreated()->json('data.id');
        $this->assertSame(2, ProjectUnit::query()->count());

        $review = $this->getJson("/api/v1/projects/{$id}/review")->assertOk()
            ->assertJsonPath('data.project.text_review', 'ready')
            ->assertJsonPath('data.project.raw', 'فلل حديثة بتشطيب فاخر وحدائق خاصة')
            ->assertJsonPath('data.project.suggested', 'فلل حديثة بتشطيب فاخر وحدائق خاصة — مصقول.')
            ->assertJsonCount(2, 'data.units')->assertJsonPath('data.units.0.text_review', 'ready');
        $this->apiContract('project_review', $review);
        $this->apiContract('projects_mine', $this->getJson('/api/v1/projects/mine')->assertOk()->assertJsonPath('data.0.id', $id)->assertJsonPath('data.0.status', 'draft'));

        $this->postJson("/api/v1/projects/{$id}/review", ['decision' => 'edit', 'text' => 'فلل حديثة بحدائق خاصة وتشطيب فاخر.'])->assertOk();
        $this->postJson("/api/v1/projects/{$id}/units/review", ['decisions' => [
            ['unit_id' => $first->json('data.id'), 'decision' => 'approve'], ['unit_id' => $second, 'decision' => 'reject'],
        ]])->assertOk();
        $published = $this->postJson("/api/v1/projects/{$id}/publish")->assertOk()->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.description', 'فلل حديثة بحدائق خاصة وتشطيب فاخر.')->assertJsonPath('data.start_price', 1950000);
        $this->apiContract('project_published', $published);

        // an edit: the title as it is; a new text goes back to its wording, and meanwhile the public reads his own
        // text — as «reject» or the auto-publish after an hour would publish it (three-parties.md)
        $this->patchJson("/api/v1/projects/{$id}", ['title' => 'مشروع الأفق الجديد', 'description' => 'فلل حديثة بحدائق خاصة ومسبح لكل فيلا'])->assertOk()
            ->assertJsonPath('data.title', 'مشروع الأفق الجديد')->assertJsonPath('data.status', 'published');
        $this->getJson("/api/v1/projects/{$id}/review")->assertJsonPath('data.project.raw', 'فلل حديثة بحدائق خاصة ومسبح لكل فيلا')
            ->assertJsonPath('data.project.text_review', 'ready');
        $this->getJson("/api/v1/projects/{$id}")->assertJsonPath('data.description', 'فلل حديثة بحدائق خاصة ومسبح لكل فيلا');

        $edited = $this->patchJson("/api/v1/projects/{$id}/units/{$second}", ['price' => 2300000])->assertOk()->assertJsonPath('data.price', 2300000);
        $this->apiContract('project_unit_updated', $edited);
        $this->deleteJson("/api/v1/projects/{$id}/units/{$second}")->assertOk();
        $this->assertNull(ProjectUnit::query()->find($second));
        $this->deleteJson("/api/v1/projects/{$id}/units/{$first->json('data.id')}")->assertStatus(409)->assertJsonPath('error.code', 'PROJECT_NO_UNITS');
        $this->assertNotNull(ProjectUnit::query()->find($first->json('data.id')), 'a published project keeps one unit at least');

        $this->apiContract('project_paused', $this->postJson("/api/v1/projects/{$id}/pause")->assertOk()->assertJsonPath('data.status', 'paused'));
        $this->postJson("/api/v1/projects/{$id}/pause")->assertStatus(409);
        $this->getJson("/api/v1/projects/{$id}")->assertOk();                      // his own, paused
        $this->signIn(Customer::factory()->create());
        $this->getJson("/api/v1/projects/{$id}")->assertNotFound();                // nobody else's
        $this->signIn($developer);
        $this->postJson("/api/v1/projects/{$id}/resume")->assertStatus(409)->assertJsonPath('error.details.reason', 'text_review');   // all of publishing asked again
        $this->postJson("/api/v1/projects/{$id}/review", ['decision' => 'approve'])->assertOk();
        $this->postJson("/api/v1/projects/{$id}/resume")->assertOk()->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.description', 'فلل حديثة بحدائق خاصة ومسبح لكل فيلا — مصقول.');
        $this->assertSame($developer->id, Project::query()->sole()->developer_id);
    }

    public function test_the_brokers_of_its_city_by_their_matching_clients_none_of_them_shown(): void
    {
        $this->aiCleans();
        $broker = Customer::factory()->broker()->create(['name' => 'مكتب النرجس']);
        $this->signIn($broker);
        foreach ([['النرجس'], [], ['الملقا']] as $i => $hoods) {
            $this->postJson('/api/v1/clients', ['name' => "عميل سري {$i}", 'phone' => '+96655000000'.$i, 'city' => 'الرياض', 'neighborhoods' => $hoods])->assertCreated();
        }
        $other = Customer::factory()->broker()->create();
        $this->signIn($other);
        $this->postJson('/api/v1/clients', ['name' => 'عميل جدة', 'city' => 'جدة'])->assertCreated();

        $developer = $this->signIn(Customer::factory()->developer()->create());
        $id = $this->postJson('/api/v1/projects', $this->project())->json('data.id');
        $this->getJson("/api/v1/ai/matching/projects/{$id}/brokers")->assertStatus(409);   // published first
        $unit = $this->postJson("/api/v1/projects/{$id}/units", $this->unit('C1'))->json('data.id');
        $this->postJson("/api/v1/projects/{$id}/review", ['decision' => 'approve'])->assertOk();
        $this->postJson("/api/v1/projects/{$id}/units/review", ['decisions' => [['unit_id' => $unit, 'decision' => 'approve']]])->assertOk();
        $this->postJson("/api/v1/projects/{$id}/publish")->assertOk();

        $brokers = $this->getJson("/api/v1/ai/matching/projects/{$id}/brokers")->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.broker.name', 'مكتب النرجس')->assertJsonPath('data.0.clients', 2);   // النرجس and «any», not الملقا
        $this->assertStringNotContainsString('عميل سري', $brokers->getContent(), 'his clients stay his');
        $this->assertStringNotContainsString('+9665', $brokers->getContent());
        $this->apiContract('project_brokers', $brokers);

        $this->signIn(Customer::factory()->developer()->create());
        $this->getJson("/api/v1/ai/matching/projects/{$id}/brokers")->assertNotFound();
        $this->getJson("/api/v1/projects/{$id}/review")->assertNotFound();
        $this->patchJson("/api/v1/projects/{$id}", ['title' => 'ليس لي'])->assertNotFound();
        $this->deleteJson("/api/v1/projects/{$id}/units/{$unit}")->assertNotFound();
        $this->getJson('/api/v1/projects/mine')->assertOk()->assertJsonCount(0, 'data');
        $this->assertSame($developer->id, Project::query()->sole()->developer_id);
    }
}
