<?php

namespace Tests\Feature\Jobs;

use App\Enums\ListingStatus;
use App\Enums\TextReview;
use App\Indexing\IndexPayloads;
use App\Jobs\CleanListingText;
use App\Models\Customer;
use App\Models\Listing;
use App\Models\PropertyOwner;
use Illuminate\Http\Client\Request as Sent;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * R-03 (review of 21 Nov): «أنا فهد السالمي» in a listing's text stayed whole on its way to the AI and the index —
 * the masking knows a name only when it is given one. The advertiser's own and his owner card's now are.
 */
class KnownNamesTest extends TestCase
{
    public function test_the_advertisers_and_the_owner_cards_names_never_leave_in_the_text(): void
    {
        $broker = Customer::factory()->broker()->create(['name' => 'سعد العتيبي']);
        $listing = Listing::factory()->create(['customer_id' => $broker->id, 'status' => ListingStatus::Indexing, 'text_review' => TextReview::Pending,
            'desc_raw' => 'شقة للبيع، المالك فهد السالمي، والتواصل مع سعد العتيبي']);
        PropertyOwner::query()->create(['broker_id' => $broker->id, 'name' => 'فهد السالمي', 'phone' => '+966551234567', 'listing_id' => $listing->id]);
        Http::fake(['agents.test/agents/*' => Http::response(['output' => ['desc_clean' => 'شقة للبيع', 'features' => []], 'degraded' => false, 'usage' => [], 'latency_ms' => 900])]);

        $this->app->call([new CleanListingText($listing->id), 'handle']);

        Http::assertSent(function (Sent $r) {
            $sent = json_encode($r->data(), JSON_UNESCAPED_UNICODE);

            return str_ends_with($r->url(), '/agents/clean_listing') && ! str_contains($sent, 'فهد') && ! str_contains($sent, 'العتيبي');
        });
        $indexed = (string) IndexPayloads::listing($listing->fresh()->forceFill(['desc_clean' => null, 'desc_raw' => 'المالك فهد السالمي']))['desc_clean'];
        $this->assertStringNotContainsString('فهد', $indexed);
    }
}
