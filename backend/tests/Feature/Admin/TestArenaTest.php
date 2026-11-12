<?php

namespace Tests\Feature\Admin;

use App\Ai\Masking\AiMasking;
use App\Arena\ArenaSamples;
use App\Arena\TestArena;
use App\Enums\IndexEntity;
use App\Filament\Pages\TestArenaPage;
use App\Models\ArenaBatch;
use App\Models\BuyerPreference;
use App\Models\Customer;
use App\Models\IndexEvent;
use App\Models\Listing;
use App\Models\SearchRequest;
use App\Support\Permissions\Permission;
use Database\Seeders\ReferenceSeeder;
use InvalidArgumentException;
use Livewire\Livewire;

/**
 * «ساحة الاختبار»: a batch fills a city with samples a review account sees and no real user does, every sample
 * reads like the market and carries no contact, and one button takes all of it away — rows and index points —
 * leaving real data as it was.
 */
class TestArenaTest extends AdminTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceSeeder::class);
    }

    private function batch(int $listings = 40, int $requests = 12, int $wishes = 9): ArenaBatch
    {
        return app(TestArena::class)->generate('الرياض', [], $listings, $requests, $wishes, 25, $this->signInSuperadmin());
    }

    public function test_a_batch_fills_the_city_in_the_sandbox_with_samples_that_read_like_the_market(): void
    {
        $batch = $this->batch();

        $accounts = Customer::query()->where('arena_batch', $batch->id)->get();
        $this->assertTrue($accounts->every(fn (Customer $c) => $c->is_tester && str_starts_with($c->phone, '+999')));
        $listings = Listing::query()->live()->whereIn('customer_id', $accounts->modelKeys())->get();
        $this->assertCount(40, $listings);
        $this->assertSame(12, SearchRequest::query()->whereIn('customer_id', $accounts->modelKeys())->count());
        $this->assertSame(9, BuyerPreference::query()->whereIn('customer_id', $accounts->modelKeys())->count());
        foreach ($listings as $listing) {
            $this->assertSame('الرياض', $listing->city);
            $this->assertGreaterThan(0, (float) $listing->price);
            $this->assertSame($listing->desc_raw, AiMasking::text((string) $listing->desc_raw), 'no contact in a sample');
            $this->assertStringContainsString((string) $listing->neighborhood, (string) $listing->title);
        }
        $this->assertGreaterThan(3, $listings->pluck('property_type')->unique()->count(), 'several kinds of property');
        $this->assertSame(40, IndexEvent::query()->where('entity', IndexEntity::Listing)->whereNotNull('payload')->count());
        $this->assertSame(12, IndexEvent::query()->where('entity', IndexEntity::Request)->whereNotNull('payload')->count());
    }

    public function test_a_review_account_sees_the_samples_and_no_real_user_does(): void
    {
        $this->batch(listings: 10, requests: 0, wishes: 0);
        $sample = Listing::query()->whereIn('customer_id', Customer::query()->whereNotNull('arena_batch')->select('id'))->firstOrFail();

        $this->getJson('/api/v1/listings?city=الرياض')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/listings/{$sample->id}")->assertNotFound();
        $this->signIn(Customer::factory()->seeker()->create());
        $this->getJson('/api/v1/listings?city=الرياض&per_page=50')->assertOk()->assertJsonCount(0, 'data');

        $this->signIn(Customer::factory()->seeker()->create(['is_tester' => true]));
        $this->getJson('/api/v1/listings?city=الرياض&per_page=50')->assertOk()->assertJsonCount(10, 'data');
        $this->getJson("/api/v1/listings/{$sample->id}")->assertOk();
    }

    public function test_one_button_removes_the_batch_its_rows_and_its_index_points_and_nothing_else(): void
    {
        $real = Listing::factory()->published()->create();
        $batch = $this->batch(listings: 15, requests: 6, wishes: 4);
        $ids = Listing::query()->whereIn('customer_id', $batch->accounts()->select('id'))->pluck('id')->all();

        $removed = app(TestArena::class)->remove($batch, $this->signInSuperadmin());

        $this->assertSame(['listings' => 15, 'requests' => 6, 'wishes' => 4], array_intersect_key($removed, array_flip(['listings', 'requests', 'wishes'])));
        $this->assertSame(0, Customer::query()->whereNotNull('arena_batch')->count());
        $this->assertSame(0, Listing::withTrashed()->whereKey($ids)->count());
        $this->assertSame(0, ArenaBatch::query()->count());
        foreach ($ids as $id) {
            $this->assertNull(IndexEvent::query()->where('entity', IndexEntity::Listing)->where('entity_id', $id)->latest('version')->firstOrFail()->payload, 'out of the index');
        }
        $this->assertTrue($real->fresh()->isLive(), 'real data untouched');
    }

    public function test_the_same_batch_draws_the_same_samples(): void
    {
        $hoods = ['النرجس', 'الملقا', 'العارض'];
        $this->assertEquals((new ArenaSamples('01JX'))->listing('الرياض', $hoods, 20), (new ArenaSamples('01JX'))->listing('الرياض', $hoods, 20));
        $this->assertNotEquals((new ArenaSamples('01JX'))->listing('الرياض', $hoods, 20), (new ArenaSamples('01JY'))->listing('الرياض', $hoods, 20));
    }

    public function test_limits_and_unknown_places_are_refused(): void
    {
        $by = $this->signInSuperadmin();
        foreach ([['الرياض', [], TestArena::MAX_LISTINGS + 1, 0, 0], ['مدينة لا توجد', [], 5, 0, 0], ['الرياض', ['حي لا يوجد'], 5, 0, 0], ['الرياض', [], 0, 0, 0]] as [$city, $hoods, $l, $r, $w]) {
            try {
                app(TestArena::class)->generate($city, $hoods, $l, $r, $w, 20, $by);
                $this->fail("accepted: {$city} {$l}");
            } catch (InvalidArgumentException) {
                $this->assertSame(0, ArenaBatch::query()->count());
            }
        }
    }

    public function test_the_page_is_the_superadmins_and_makes_and_removes_a_batch(): void
    {
        $this->signInStaff(Permission::ViewHealth);
        $this->get(TestArenaPage::getUrl())->assertForbidden();

        $this->signInSuperadmin();
        Livewire::test(TestArenaPage::class)
            ->fillForm(['city' => 'الرياض', 'listings' => 5, 'requests' => 3, 'wishes' => 2, 'rent' => 0])
            ->call('mountAction', 'generate', [], ['schemaComponent' => 'content'])
            ->assertHasNoFormErrors();
        $this->assertSame(1, ArenaBatch::query()->count());

        Livewire::test(TestArenaPage::class)->callTableAction('remove', ArenaBatch::query()->sole());
        $this->assertSame(0, ArenaBatch::query()->count());
    }
}
