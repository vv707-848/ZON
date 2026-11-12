<?php

namespace Tests\Feature\Api;

use App\Enums\Delivery;
use App\Enums\Purpose;
use App\Models\AdLicense;
use App\Models\Customer;
use App\Models\Listing;
use App\Models\Media;
use App\Models\Project;
use App\Models\ProjectUnit;
use Tests\TestCase;

/**
 * «الرئيسية» on the server (GET /feed): listings and projects in one list, with the app's filter card — purpose,
 * city, neighbourhood, source, type — and its orders, the featured first; what a viewer must not see is left out.
 * Its rows, and a listing's and a project's detail, are the app's recorded contract (docs/contracts/fixtures/api).
 */
class FeedTest extends TestCase
{
    /** @return array{broker: Listing, rent: Listing, owner: Listing, project: Project, featured: Listing} */
    private function market(): array
    {
        $broker = Listing::factory()->published()->create(['price' => 900000, 'area_built' => 150, 'published_at' => now()->subDays(3)]);
        $rent = Listing::factory()->published()->create(['purpose' => Purpose::Rent, 'price' => 60000, 'area_built' => 120, 'neighborhood' => 'الملقا', 'title' => 'شقة للإيجار في الملقا', 'published_at' => now()->subDays(2)]);
        $owner = Listing::factory()->published()->create(['customer_id' => Customer::factory()->owner(), 'property_type' => 'villa', 'price' => 2400000, 'area_built' => 400, 'published_at' => now()->subDay()]);
        $featured = Listing::factory()->published()->create(['price' => 700000, 'area_built' => 100, 'featured_until' => now()->addDays(3), 'published_at' => now()->subDays(9)]);
        $project = Project::factory()->published()->create(['delivery' => Delivery::Offplan, 'published_at' => now()->subDays(5)]);
        ProjectUnit::factory()->create(['project_id' => $project->id, 'price' => 1800000]);
        ProjectUnit::factory()->create(['project_id' => $project->id, 'price' => 1500000, 'available_count' => 0]);   // sold out: not the «from»

        return compact('broker', 'rent', 'owner', 'project', 'featured');
    }

    private function photo(Listing|Project|ProjectUnit $owner): Media
    {
        $customer = match (true) {
            $owner instanceof Listing => $owner->customer_id, $owner instanceof Project => $owner->developer_id, default => $owner->project->developer_id
        };

        return Media::query()->create(['mediable_type' => $owner->getMorphClass(), 'mediable_id' => $owner->id, 'customer_id' => $customer, 'disk' => 'minio',
            'path' => "media/{$customer}/01JTEST{$owner->id}.jpg", 'mime' => 'image/jpeg', 'size' => 1000, 'width' => 2048, 'height' => 1536, 'is_cover' => true, 'sizes' => ['large', 'card']]);
    }

    /** @return list<string> */
    private function feed(string $query = ''): array
    {
        return array_map(fn (array $r) => "{$r['kind']}:{$r['id']}", $this->getJson("/api/v1/feed{$query}")->assertOk()->json('data'));
    }

    public function test_listings_and_projects_come_in_one_list_with_the_featured_first(): void
    {
        $m = $this->market();
        $this->signIn();

        $this->assertSame(["listing:{$m['featured']->id}", "listing:{$m['owner']->id}", "listing:{$m['rent']->id}", "listing:{$m['broker']->id}", "project:{$m['project']->id}"], $this->feed());
        $this->assertSame(["listing:{$m['featured']->id}", "project:{$m['project']->id}", "listing:{$m['broker']->id}", "listing:{$m['rent']->id}", "listing:{$m['owner']->id}"], $this->feed('?sort=old'));
        $this->assertSame(["listing:{$m['featured']->id}"], $this->feed('?sort=featured'));
        $this->assertSame(["listing:{$m['rent']->id}", "listing:{$m['featured']->id}", "listing:{$m['broker']->id}", "project:{$m['project']->id}", "listing:{$m['owner']->id}"], $this->feed('?sort=price_asc'));
        $this->assertSame(["listing:{$m['owner']->id}", "listing:{$m['broker']->id}", "listing:{$m['rent']->id}", "listing:{$m['featured']->id}", "project:{$m['project']->id}"], $this->feed('?sort=area_desc'));

        $this->photo($m['owner']);
        $response = $this->getJson('/api/v1/feed?per_page=5');
        $rows = $response->json('data');
        $this->assertIsArray($rows);
        $project = array_values(array_filter($rows, fn (array $r) => $r['kind'] === 'project'))[0];
        $this->assertEquals(1800000, $project['start_price'], 'the «from» price is the cheapest unit still available');
        $this->assertSame(5, $response->json('meta.page.total'));
        $this->apiContract('feed', $response);
    }

    public function test_the_filter_card_narrows_it_as_the_app_does(): void
    {
        $m = $this->market();

        $this->assertSame(["listing:{$m['rent']->id}"], $this->feed('?purpose=rent'), 'a project is never for rent');
        $this->assertSame(["project:{$m['project']->id}"], $this->feed('?source=developer'));
        $this->assertSame(["listing:{$m['owner']->id}"], $this->feed('?source=owner'));
        $this->assertSame(["listing:{$m['rent']->id}"], $this->feed('?city=الرياض&neighborhood=الملقا'));
        $this->assertSame(["listing:{$m['owner']->id}", "project:{$m['project']->id}"], $this->feed('?property_type=villa'));
        $this->getJson('/api/v1/feed?sort=random')->assertStatus(422);
    }

    public function test_what_a_viewer_must_not_see_stays_out(): void
    {
        $m = $this->market();
        $tester = Listing::factory()->published()->create(['customer_id' => Customer::factory()->broker()->create(['is_tester' => true])]);
        AdLicense::query()->where('listing_id', $m['broker']->id)->update(['expires_at' => now()->subDay()]);   // its licence ran out

        $this->assertNotContains("listing:{$tester->id}", $this->feed());
        $this->assertNotContains("listing:{$m['broker']->id}", $this->feed());
        $this->signIn(Customer::factory()->seeker()->create(['is_tester' => true]));
        $this->assertContains("listing:{$tester->id}", $this->feed(), 'a review account sees the sandbox');
    }

    public function test_a_listing_and_a_project_in_detail_are_part_of_the_contract(): void
    {
        $m = $this->market();
        $this->photo($m['owner']);
        $this->photo($m['project']);
        $this->photo(ProjectUnit::query()->where('project_id', $m['project']->id)->where('available_count', '>', 0)->sole());
        $this->signIn();

        $this->apiContract('listing', $this->getJson("/api/v1/listings/{$m['owner']->id}")->assertOk());
        $this->apiContract('project', $this->getJson("/api/v1/projects/{$m['project']->id}")->assertOk());
    }
}
