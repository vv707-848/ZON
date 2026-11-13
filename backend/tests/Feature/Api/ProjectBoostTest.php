<?php

namespace Tests\Feature\Api;

use App\Enums\BoostVia;
use App\Jobs\Scheduled\EndBoosts;
use App\Models\BoostUsage;
use App\Models\Customer;
use App\Models\Project;
use App\Support\Settings\Setting;
use App\Support\Settings\Settings;
use Tests\TestCase;

/**
 * «تمييز» of a developer's project (decision of 13 November): free while fewer than ProjectFeaturedFree of his
 * projects are featured free at the time — so not every project of his shows featured — then paid
 * (ProjectFeaturedPrice). Both are edited from the panel's settings.
 */
class ProjectBoostTest extends TestCase
{
    /** @return list<Project> */
    private function projects(Customer $developer, int $count): array
    {
        return Project::factory()->published()->count($count)->create(['developer_id' => $developer->id])->all();
    }

    public function test_three_free_at_a_time_then_the_price_and_a_free_one_ended_frees_its_place(): void
    {
        $developer = $this->signIn(Customer::factory()->developer()->create());
        [$a, $b, $c, $d] = $this->projects($developer, 4);

        $this->apiContract('project_boosted', $this->postJson("/api/v1/projects/{$a->id}/boost", ['kind' => 'featured'])->assertOk()
            ->assertJsonPath('data.kind', 'featured')->assertJsonPath('data.via', 'quota'));
        $this->postJson("/api/v1/projects/{$a->id}/boost", ['kind' => 'featured'])->assertStatus(409)->assertJsonPath('error.code', 'ALREADY_ACTIVE');
        $this->postJson("/api/v1/projects/{$b->id}/boost", ['kind' => 'featured'])->assertOk();
        $this->postJson("/api/v1/projects/{$c->id}/boost", ['kind' => 'featured'])->assertOk();
        $this->apiContract('project_boost_price', $this->postJson("/api/v1/projects/{$d->id}/boost", ['kind' => 'featured'])->assertStatus(402)
            ->assertJsonPath('error.code', 'PAYMENT_REQUIRED')->assertJsonPath('error.details.price', '100.00')
            ->assertJsonPath('error.details.purpose', 'featured')->assertJsonPath('error.details.target', ['type' => 'project', 'id' => $d->id]));
        $this->assertNull($d->fresh()->featured_until, 'nothing changed');

        $this->getJson("/api/v1/projects/{$a->id}")->assertJsonPath('data.featured', true)->assertJsonPath('data.featured_until', $a->fresh()->featured_until->toIso8601String());
        $this->getJson('/api/v1/packages')->assertJsonPath('data.one_off.project_featured', '100.00')->assertJsonPath('data.one_off.project_featured_free', 3);

        // the panel's numbers are the ones used
        Settings::put(Setting::ProjectFeaturedFree, 4);
        Settings::put(Setting::ProjectFeaturedPrice, 25_000);
        $e = $this->projects($developer, 1)[0];
        $this->postJson("/api/v1/projects/{$d->id}/boost", ['kind' => 'featured'])->assertOk();
        $this->postJson("/api/v1/projects/{$e->id}/boost", ['kind' => 'featured'])->assertStatus(402)->assertJsonPath('error.details.price', '250.00');

        // a free featuring that ended gives its place back, and its developer hears of it once
        $this->travel(Settings::int(Setting::BoostDays) + 1)->days();
        (new EndBoosts)->handle();
        (new EndBoosts)->handle();
        $this->assertSame(4, $developer->notifications()->where('data->key', 'like', 'project-ended:featured:%')->count());
        $this->getJson("/api/v1/projects/{$a->id}")->assertJsonPath('data.featured', false)->assertJsonPath('data.featured_until', null);
        $this->postJson("/api/v1/projects/{$e->id}/boost", ['kind' => 'featured'])->assertOk()->assertJsonPath('data.via', 'quota');
    }

    public function test_the_paid_one_goes_through_checkout_and_does_not_take_a_free_place(): void
    {
        $developer = $this->signIn(Customer::factory()->developer()->create());
        Settings::put(Setting::ProjectFeaturedFree, 0);
        [$paid, $free] = $this->projects($developer, 2);

        $checkout = $this->postJson('/api/v1/checkout', ['purpose' => 'featured', 'target' => ['type' => 'project', 'id' => $paid->id]])->assertCreated()
            ->assertJsonPath('data.amount', '100.00');
        $this->apiContract('checkout_project_featured', $checkout);
        $payment = $checkout->json('data.payment_id');
        $this->apiContract('payment_project_featured', $this->postJson("/api/v1/payments/{$payment}/confirm", ['reference' => "fake_{$payment}"])
            ->assertJsonPath('data.status', 'paid'));
        $this->apiContract('project_featured', $this->getJson("/api/v1/projects/{$paid->id}")->assertJsonPath('data.featured', true));
        $this->assertTrue($paid->fresh()->featured_until->isFuture());
        $this->assertSame(BoostVia::Payment, BoostUsage::query()->where('target_id', $paid->id)->sole()->via);
        $this->postJson('/api/v1/checkout', ['purpose' => 'featured', 'target' => ['type' => 'project', 'id' => $paid->id]])->assertStatus(409);

        Settings::put(Setting::ProjectFeaturedFree, 1);
        $this->postJson("/api/v1/projects/{$free->id}/boost", ['kind' => 'featured'])->assertOk()->assertJsonPath('data.via', 'quota');
    }

    public function test_only_his_published_projects(): void
    {
        $developer = $this->signIn(Customer::factory()->developer()->create());
        $draft = Project::factory()->create(['developer_id' => $developer->id]);
        $other = Project::factory()->published()->create();

        $this->postJson("/api/v1/projects/{$draft->id}/boost", ['kind' => 'featured'])->assertStatus(409)->assertJsonPath('error.code', 'INVALID_TRANSITION');
        $this->postJson("/api/v1/projects/{$other->id}/boost", ['kind' => 'featured'])->assertNotFound();
        $this->postJson('/api/v1/checkout', ['purpose' => 'featured', 'target' => ['type' => 'project', 'id' => $other->id]])->assertNotFound();
        $this->postJson("/api/v1/projects/{$draft->id}/boost", ['kind' => 'urgent_listing'])->assertStatus(422);
        $this->assertSame(0, BoostUsage::query()->count());
    }
}
