<?php

namespace App\Jobs\Scheduled;

use App\Actions\Listings\PublishListing;
use App\Enums\ListingStatus;
use App\Enums\TextReview;
use App\Indexing\IndexSync;
use App\Jobs\ScheduledJob;
use App\Models\Listing;
use App\Models\ProjectUnit;
use App\Support\Actor;
use App\Support\Settings\Setting;
use App\Support\Settings\Settings;

/**
 * No decision within Setting::ReviewAutoPublishHours: the listing is published with the advertiser's own text
 * (text_review = auto) and his review button stays; unit models of published projects become searchable the same way.
 */
class AutoPublishPendingReviews extends ScheduledJob
{
    public function handle(): void
    {
        $limit = now()->subHours(Settings::int(Setting::ReviewAutoPublishHours));
        $publish = app(PublishListing::class);
        $index = app(IndexSync::class);

        Listing::query()->with('customer')->where('status', ListingStatus::PendingReview)
            ->whereHas('history', fn ($q) => $q->where('field', 'status')->where('new_value', ListingStatus::PendingReview->value)->where('changed_at', '<=', $limit))
            ->chunkById(100, function ($listings) use ($publish) {
                foreach ($listings as $listing) {
                    $listing->forceFill(['text_review' => TextReview::Auto, 'text_reviewed_at' => now()])->save();
                    $publish($listing, Actor::system());
                }
            });

        ProjectUnit::query()->with('project')->where('text_review', TextReview::Ready)->where('updated_at', '<=', $limit)
            ->whereHas('project', fn ($q) => $q->where('status', ListingStatus::Published))
            ->chunkById(200, function ($units) use ($index) {
                foreach ($units as $unit) {
                    $unit->forceFill(['text_review' => TextReview::Auto])->save();
                    $index->unit($unit);
                }
            });
    }
}
