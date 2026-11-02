<?php

namespace Tests\Feature;

use App\Enums\CustomerRole;
use App\Enums\Delivery;
use App\Enums\IndexEntity;
use App\Enums\LicenceSource;
use App\Enums\LicenceStatus;
use App\Enums\ListingStatus;
use App\Indexing\IndexSync;
use App\Integrations\Rega\FakeRegaGateway;
use App\Integrations\Rega\LicenceCheck;
use App\Integrations\Rega\RegaGateway;
use App\Jobs\Scheduled\ExpireLicences;
use App\Jobs\Scheduled\InactivateStaleListings;
use App\Jobs\Scheduled\RemindLicenceExpiry;
use App\Jobs\Scheduled\ResumePausedListings;
use App\Models\AdLicense;
use App\Models\Customer;
use App\Models\IndexEvent;
use App\Models\Listing;
use App\Models\Project;
use App\Models\ProjectUnit;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * An advertising licence's life: it counts through its last day in Riyadh and not a minute more, its advertiser
 * is warned a week and a day before, a renewal brings the listing back — and REGA's date is the only date.
 */
class LicenceLifecycleTest extends TestCase
{
    private function at(string $riyadh): void
    {
        $this->travelTo(CarbonImmutable::parse($riyadh, AdLicense::TIMEZONE));
    }

    private function listingEndingOn(string $lastDay, array $attributes = []): Listing
    {
        $listing = Listing::factory()->published()->create($attributes);
        $listing->licence->forceFill(['expires_at' => $lastDay])->save();

        return $listing->fresh();
    }

    private function lastIndexAction(IndexEntity $entity, int $id): ?string
    {
        return IndexEvent::query()->where('entity', $entity)->where('entity_id', $id)->orderByDesc('version')->first()?->action->value;
    }

    public function test_a_licence_counts_through_its_last_day_in_riyadh_and_not_a_minute_more(): void
    {
        $this->at('2027-03-10 08:00');
        $listing = $this->listingEndingOn('2027-03-10');
        $username = $listing->customer->username;

        $this->at('2027-03-10 23:59');
        $this->getJson('/api/v1/listings')->assertOk()->assertJsonPath('data.0.id', $listing->id);
        $this->getJson("/api/v1/listings/{$listing->id}")->assertOk()->assertJsonPath('data.licence.expires_at', '2027-03-10');

        $this->at('2027-03-11 00:00');
        $this->getJson('/api/v1/listings')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/listings/{$listing->id}")->assertNotFound();
        $this->getJson("/api/v1/customers/{$username}/listings")->assertOk()->assertJsonCount(0, 'data');
        $this->assertSame('published', $listing->fresh()->status->value, 'no job ran: the rule alone took it out');
    }

    public function test_the_night_job_records_the_end_takes_it_out_of_the_index_and_tells_the_advertiser(): void
    {
        $this->at('2027-03-10 08:00');
        $listing = $this->listingEndingOn('2027-03-10');
        app(IndexSync::class)->listing($listing);
        $sold = $this->listingEndingOn('2027-03-10', ['status' => ListingStatus::Sold]);

        $this->at('2027-03-11 00:05');
        (new ExpireLicences)->handle();
        (new ExpireLicences)->handle();

        $listing->refresh();
        $this->assertSame(['licence_expired', 'expired'], [$listing->status->value, $listing->licence->status->value]);
        $this->assertSame('delete', $this->lastIndexAction(IndexEntity::Listing, $listing->id));
        $this->assertSame(1, $listing->customer->notifications()->where('data->key', "licence-expired:{$listing->id}")->count());
        $this->assertSame(['sold', 'expired'], [$sold->fresh()->status->value, $sold->fresh()->licence->status->value], 'a sale stays a sale');
        $this->assertSame(0, $sold->customer->notifications()->count());
    }

    public function test_the_advertiser_is_warned_a_week_and_a_day_before_each_once(): void
    {
        $this->at('2027-03-01 10:00');
        $listing = $this->listingEndingOn('2027-03-10');
        $keys = fn () => $listing->customer->notifications()->pluck('data')->pluck('key')->sort()->values()->all();

        (new RemindLicenceExpiry)->handle();
        $this->assertSame([], $keys(), 'nine days left: nothing yet');

        $this->at('2027-03-03 10:00');
        (new RemindLicenceExpiry)->handle();
        $this->at('2027-03-05 10:00');
        (new RemindLicenceExpiry)->handle();
        $this->assertSame(["licence-expiring:{$listing->id}:7"], $keys());
        $this->assertStringContainsString('تنتهي خلال 7 أيام', $listing->customer->notifications()->sole()->data['body']);

        $this->at('2027-03-09 10:00');
        (new RemindLicenceExpiry)->handle();
        (new RemindLicenceExpiry)->handle();
        $this->assertSame(["licence-expiring:{$listing->id}:1", "licence-expiring:{$listing->id}:7"], $keys());
    }

    public function test_a_renewal_brings_the_listing_back_and_keeps_the_old_licence_on_record(): void
    {
        $this->at('2027-03-10 08:00');
        $listing = $this->listingEndingOn('2027-03-10');
        $this->at('2027-03-11 00:05');
        (new ExpireLicences)->handle();
        $this->signIn($listing->customer);

        $this->postJson("/api/v1/listings/{$listing->id}/licence", ['licence_number' => '7200990000'])->assertStatus(422);
        $this->postJson("/api/v1/listings/{$listing->id}/licence", ['licence_number' => '7200990011'])->assertOk()
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.licence.number', '7200990011')
            ->assertJsonPath('data.licence.expires_at', '2028-03-11');

        $this->assertTrue($listing->fresh()->isLive());
        $this->assertSame(2, AdLicense::query()->where('listing_id', $listing->id)->count());
        $this->assertSame('create', $this->lastIndexAction(IndexEntity::Listing, $listing->id));
    }

    public function test_nothing_publishes_a_listing_whose_licence_ended(): void
    {
        $this->at('2027-03-08 08:00');
        $paused = $this->listingEndingOn('2027-03-10');
        $paused->forceFill(['status' => ListingStatus::Paused, 'paused_until' => now()->addDays(4)])->save();
        $mine = $this->listingEndingOn('2027-03-10');
        $mine->forceFill(['status' => ListingStatus::Paused])->save();

        $this->at('2027-03-12 09:00');
        (new ResumePausedListings)->handle();
        $this->assertSame('licence_expired', $paused->fresh()->status->value, 'the end of a pause');

        $this->signIn($mine->customer);
        $this->postJson("/api/v1/listings/{$mine->id}/resume")->assertOk()->assertJsonPath('data.status', 'licence_expired');
        $this->postJson("/api/v1/listings/{$mine->id}/boost", ['kind' => 'featured'])->assertStatus(409);
    }

    public function test_rega_is_the_only_source_of_the_date(): void
    {
        $listing = Listing::factory()->published()->create();
        $this->app->instance(RegaGateway::class, new class extends FakeRegaGateway
        {
            public function verifyAdLicence(string $number): LicenceCheck
            {
                return new LicenceCheck(true, $number);
            }
        });
        $this->signIn($listing->customer);

        $this->postJson("/api/v1/listings/{$listing->id}/licence", ['licence_number' => '7200990011'])
            ->assertStatus(502)->assertJsonPath('error.code', 'INTEGRATION_FAILED');
        $this->assertSame(1, AdLicense::query()->where('listing_id', $listing->id)->count(), 'nothing saved without REGA\'s date');
    }

    public function test_a_unit_model_leaves_the_index_with_its_licence_and_comes_back_with_the_renewal(): void
    {
        $this->at('2027-03-10 08:00');
        $project = Project::factory()->published()->create(['delivery' => Delivery::Ready]);
        $unit = ProjectUnit::factory()->create(['project_id' => $project->id]);
        AdLicense::query()->create([
            'project_unit_id' => $unit->id, 'customer_id' => $project->developer_id, 'licence_number' => '7300000001',
            'source' => LicenceSource::Entered, 'status' => LicenceStatus::Valid, 'verified_at' => now(), 'expires_at' => '2027-03-10',
        ]);
        app(IndexSync::class)->unit($unit->fresh());
        $this->assertSame('create', $this->lastIndexAction(IndexEntity::ProjectUnit, $unit->id));

        $this->at('2027-03-11 00:05');
        (new ExpireLicences)->handle();
        $this->assertSame('delete', $this->lastIndexAction(IndexEntity::ProjectUnit, $unit->id));
        $this->assertSame(1, $project->developer->notifications()->where('data->key', "unit-licence-expired:{$unit->id}")->count());

        $this->signIn($project->developer);
        $this->postJson("/api/v1/projects/{$project->id}/units/{$unit->id}/licence", ['licence_number' => '7300000002'])->assertOk()
            ->assertJsonPath('data.licence.expires_at', '2028-03-11');
        $this->assertSame('create', $this->lastIndexAction(IndexEntity::ProjectUnit, $unit->id));
    }

    public function test_two_weeks_without_an_update_make_a_broker_listing_inactive_and_only_a_broker_one(): void
    {
        $broker = Listing::factory()->published()->create(['last_activity_at' => now()->subDays(15)]);
        $owner = Listing::factory()->published()->create([
            'customer_id' => Customer::factory()->owner(), 'last_activity_at' => now()->subDays(15),
        ]);

        (new InactivateStaleListings)->handle();

        $this->assertSame(CustomerRole::Owner, $owner->customer->role);
        $this->assertSame(['inactive', 'published'], [$broker->fresh()->status->value, $owner->fresh()->status->value]);
    }
}
