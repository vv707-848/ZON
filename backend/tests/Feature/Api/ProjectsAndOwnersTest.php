<?php

namespace Tests\Feature\Api;

use App\Enums\Delivery;
use App\Enums\ListingStatus;
use App\Models\CommissionDue;
use App\Models\Customer;
use App\Models\IndexEvent;
use App\Models\MarketingContract;
use App\Models\Payment;
use App\Models\Project;
use App\Models\ProjectUnit;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** A developer's project with its two reviews, and an owner's path from contract to listing to sale. */
class ProjectsAndOwnersTest extends TestCase
{
    private function aiCleans(): void
    {
        Http::fake(function (Request $request) {
            $usage = ['usage' => ['tokens_in' => 10, 'tokens_out' => 10], 'latency_ms' => 100, 'degraded' => false];

            return match (true) {
                str_ends_with($request->url(), '/agents/clean_project') => Http::response(['output' => ['desc_clean' => 'فلل حديثة بتشطيب فاخر.']] + $usage),
                str_ends_with($request->url(), '/agents/clean_units') => Http::response(['output' => ['units' => array_map(
                    fn (array $u) => ['id' => $u['id'], 'desc_clean' => "نموذج {$u['model']} المصقول."], $request['units']) + [99 => ['id' => 999999, 'desc_clean' => 'مخترع']]]] + $usage),
                str_ends_with($request->url(), '/agents/clean_listing') => Http::response(['output' => ['desc_clean' => 'عرض المالك المصقول.']] + $usage),
                default => null,
            };
        });
    }

    public function test_a_developer_publishes_a_project_after_both_reviews(): void
    {
        $this->aiCleans();
        $developer = $this->signIn(Customer::factory()->developer()->create());
        $project = $this->postJson('/api/v1/projects', [
            'title' => 'مشروع الأفق', 'city' => 'الرياض', 'neighborhood' => 'النرجس', 'property_type' => 'villa', 'delivery' => 'offplan',
            'total_units' => 20, 'description' => 'فلل حديثة بتشطيب فاخر جداً للتواصل 0551234567',
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/projects/{$project}/publish")->assertStatus(409)->assertJsonPath('error.details.reason', 'text_review');
        $this->postJson("/api/v1/projects/{$project}/review", ['decision' => 'approve'])->assertOk();
        $this->postJson("/api/v1/projects/{$project}/publish")->assertStatus(409)->assertJsonPath('error.code', 'PROJECT_NO_UNITS');

        $unit = $this->postJson("/api/v1/projects/{$project}/units", ['model_name' => 'C1', 'property_type' => 'villa', 'area_built' => 380, 'bedrooms' => 5,
            'price' => '1,950,000', 'available_count' => 6, 'description' => 'نموذج بخمس غرف ومجلس مستقل'])->assertCreated()->json('data.id');
        $this->assertSame('ready', ProjectUnit::query()->find($unit)->text_review->value, 'an invented id in the answer is dropped');

        $this->postJson("/api/v1/projects/{$project}/publish")->assertOk()->assertJsonPath('data.status', 'published')->assertJsonPath('data.start_price', 1950000);
        $this->assertFalse(IndexEvent::query()->where('entity', 'project_unit')->exists(), 'an undecided unit is not searchable');

        $this->postJson("/api/v1/projects/{$project}/units/review", ['decisions' => [['unit_id' => $unit, 'decision' => 'approve']]])->assertOk();
        $event = IndexEvent::query()->where('entity', 'project_unit')->sole();
        $this->assertSame(['create', 'نموذج C1 المصقول.', 'فلل حديثة بتشطيب فاخر.'], [$event->action->value, $event->payload['desc_clean'], $event->payload['project_context']]);

        MarketingContract::query()->create(['developer_id' => $developer->id, 'number' => 'MC-1', 'signed_at' => today(), 'commission_percent' => '2.00']);
        $this->postJson("/api/v1/projects/{$project}/units/{$unit}/sold", ['count' => 2])->assertOk()->assertJsonPath('data.available_count', 4);
        $this->assertSame([3900000, 3900000], CommissionDue::query()->pluck('amount_halalas')->map(fn ($v) => (int) $v)->all(), '2 % of 1,950,000 SAR, per unit');

        $this->getJson('/api/v1/projects?city=الرياض')->assertJsonCount(1, 'data')->assertJsonPath('data.0.available_units', 4);
    }

    public function test_a_ready_project_needs_a_licence_on_every_unit_model(): void
    {
        $developer = $this->signIn(Customer::factory()->developer()->create());
        $project = Project::factory()->create(['developer_id' => $developer->id, 'delivery' => Delivery::Ready, 'text_review' => 'approved']);
        $unit = ProjectUnit::factory()->create(['project_id' => $project->id]);

        $this->postJson("/api/v1/projects/{$project->id}/publish")->assertStatus(409)->assertJsonPath('error.code', 'LICENCE_REQUIRED')->assertJsonPath('error.details.units.0', $unit->id);
        $this->postJson("/api/v1/projects/{$project->id}/units/{$unit->id}/licence", ['licence_number' => '7100000000'])->assertStatus(422);
        $this->postJson("/api/v1/projects/{$project->id}/units/{$unit->id}/licence", ['licence_number' => '7100123456'])->assertOk()->assertJsonPath('data.licence.verified', true);
        $this->postJson("/api/v1/projects/{$project->id}/publish")->assertOk();
    }

    public function test_resume_is_only_for_a_paused_project_and_checks_everything_again(): void
    {
        $developer = $this->signIn(Customer::factory()->developer()->create());
        $draft = Project::factory()->create(['developer_id' => $developer->id, 'delivery' => Delivery::Ready]);
        ProjectUnit::factory()->create(['project_id' => $draft->id]);
        $this->postJson("/api/v1/projects/{$draft->id}/resume")->assertStatus(409)->assertJsonPath('error.code', 'INVALID_TRANSITION');

        $hidden = Project::factory()->published()->create(['developer_id' => $developer->id, 'status' => ListingStatus::Hidden]);
        $this->postJson("/api/v1/projects/{$hidden->id}/publish")->assertStatus(409)->assertJsonPath('error.code', 'INVALID_TRANSITION');
        $this->postJson("/api/v1/projects/{$hidden->id}/resume")->assertStatus(409);

        // Paused, then a model without a licence was added to the ready project: resuming asks for it.
        $paused = Project::factory()->published()->create(['developer_id' => $developer->id, 'delivery' => Delivery::Ready, 'status' => ListingStatus::Paused]);
        $unit = ProjectUnit::factory()->create(['project_id' => $paused->id]);
        $this->postJson("/api/v1/projects/{$paused->id}/resume")->assertStatus(409)->assertJsonPath('error.code', 'LICENCE_REQUIRED');
        $this->assertSame([ListingStatus::Draft, ListingStatus::Hidden, ListingStatus::Paused], [$draft->fresh()->status, $hidden->fresh()->status, $paused->fresh()->status]);

        $this->postJson("/api/v1/projects/{$paused->id}/units/{$unit->id}/licence", ['licence_number' => '7100123456'])->assertOk();
        $this->postJson("/api/v1/projects/{$paused->id}/resume")->assertOk()->assertJsonPath('data.status', 'published');
    }

    public function test_the_public_never_sees_an_unlicensed_model_of_a_ready_project(): void
    {
        $developer = Customer::factory()->developer()->create();
        $project = Project::factory()->published()->create(['developer_id' => $developer->id, 'delivery' => Delivery::Ready]);
        $unit = ProjectUnit::factory()->create(['project_id' => $project->id]);      // added after publication, no licence

        $this->getJson("/api/v1/projects/{$project->id}")->assertOk()->assertJsonCount(0, 'data.units');
        $this->getJson("/api/v1/projects/{$project->id}/units/{$unit->id}")->assertNotFound();
        $this->assertFalse(IndexEvent::query()->where('entity', 'project_unit')->exists());

        $this->signIn($developer);
        $this->getJson("/api/v1/projects/{$project->id}")->assertJsonCount(1, 'data.units');   // the developer sees his own
    }

    public function test_a_pending_developer_cannot_publish_and_others_cannot_touch_his_project(): void
    {
        $this->signIn(Customer::factory()->developer()->pending()->create());
        $this->postJson('/api/v1/projects', ['title' => 'x'])->assertStatus(422);
        $this->postJson('/api/v1/projects', ['title' => 'مشروع', 'city' => 'الرياض', 'neighborhood' => 'النرجس', 'property_type' => 'villa', 'delivery' => 'offplan', 'total_units' => 3, 'description' => 'وصف المشروع الطويل'])
            ->assertStatus(403)->assertJsonPath('error.code', 'ACCOUNT_PENDING');

        $project = Project::factory()->create();
        $this->signIn(Customer::factory()->developer()->create());
        $this->postJson("/api/v1/projects/{$project->id}/publish")->assertNotFound();
        $this->getJson("/api/v1/projects/{$project->id}")->assertNotFound();   // a draft is nobody else's business
    }

    public function test_the_owner_path_from_contract_to_licence_to_listing_to_commission(): void
    {
        $this->aiCleans();
        $owner = $this->signIn(Customer::factory()->owner()->create());

        $case = $this->postJson('/api/v1/owner-cases', ['deed_number' => '310112233445'])->assertCreated()->assertJsonPath('data.stage', 'contract')->json('data.id');
        $this->getJson("/api/v1/owner-cases/{$case}")->assertOk()->assertJsonPath('data.stage', 'fee')
            ->assertJsonPath('data.next.price', '50.00')->assertJsonPath('data.next.purpose', 'ad_licence');

        $payment = $this->postJson('/api/v1/checkout', ['purpose' => 'ad_licence', 'target' => ['type' => 'owner_case', 'id' => $case]])->assertCreated()->json('data.payment_id');
        $this->postJson("/api/v1/payments/{$payment}/confirm", ['reference' => "fake_{$payment}"])->assertOk()->assertJsonPath('data.status', 'paid');
        $this->assertSame(0, Payment::query()->sole()->vat_halalas, 'the licence fee passes to REGA without VAT');
        $this->getJson("/api/v1/owner-cases/{$case}")->assertJsonPath('data.stage', 'listing')->assertJsonPath('data.next.owner_case_id', $case);

        $listing = $this->postJson('/api/v1/listings', [
            'purpose' => 'sale', 'property_type' => 'villa', 'title' => 'فيلا المالك', 'city' => 'الرياض', 'neighborhood' => 'الملقا', 'price' => 2000000,
            'description' => 'فيلا واسعة بحديقة خلفية', 'owner_case_id' => $case,
        ])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/listings/{$listing}/review", ['decision' => 'reject'])->assertOk()->assertJsonPath('data.description', 'فيلا واسعة بحديقة خلفية');
        $this->getJson("/api/v1/listings/{$listing}")->assertJsonPath('data.licence.verified', true);

        $this->postJson("/api/v1/listings/{$listing}/sold", ['sale_price' => 1900000])->assertOk();
        $due = CommissionDue::query()->sole();
        $this->assertSame([950000, $owner->id], [(int) $due->amount_halalas, $due->customer_id], '0.5 % of 1,900,000 SAR');
        $this->getJson("/api/v1/owner-cases/{$case}")->assertJsonPath('data.stage', 'closed');
    }
}
