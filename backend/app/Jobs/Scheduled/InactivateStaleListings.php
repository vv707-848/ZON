<?php

namespace App\Jobs\Scheduled;

use App\Actions\Listings\ChangeListingStatus;
use App\Enums\ListingStatus;
use App\Jobs\ScheduledJob;
use App\Models\Listing;
use App\Notifications\Notices;
use App\Support\Actor;
use App\Support\Settings\Setting;
use App\Support\Settings\Settings;

/** Published without any update for Setting::ListingInactiveDays → «غير نشط» (an update brings it back). */
class InactivateStaleListings extends ScheduledJob
{
    public function handle(): void
    {
        $change = app(ChangeListingStatus::class);
        Listing::query()->with('customer')->where('status', ListingStatus::Published)
            ->where('last_activity_at', '<', now()->subDays(Settings::int(Setting::ListingInactiveDays)))
            ->chunkById(200, function ($listings) use ($change) {
                foreach ($listings as $listing) {
                    $change($listing, ListingStatus::Inactive, Actor::system());
                    $listing->customer->notify(Notices::listingInactive($listing));
                }
            });
    }
}
