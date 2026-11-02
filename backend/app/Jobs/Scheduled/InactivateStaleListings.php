<?php

namespace App\Jobs\Scheduled;

use App\Actions\Listings\ChangeListingStatus;
use App\Enums\CustomerRole;
use App\Enums\ListingStatus;
use App\Jobs\ScheduledJob;
use App\Models\Listing;
use App\Notifications\Notices;
use App\Support\Actor;
use App\Support\Settings\Setting;
use App\Support\Settings\Settings;

/**
 * A broker's listing published without any update for Setting::ListingInactiveDays (two weeks) → «غير نشط»; an
 * update brings it back. Brokers only, as the app tells them («عروض الوسطاء تنتقل لغير النشط كل أسبوعين»).
 */
class InactivateStaleListings extends ScheduledJob
{
    public function handle(): void
    {
        $change = app(ChangeListingStatus::class);
        Listing::query()->with('customer')->where('status', ListingStatus::Published)
            ->whereHas('customer', fn ($customer) => $customer->where('role', CustomerRole::Broker))
            ->where('last_activity_at', '<', now()->subDays(Settings::int(Setting::ListingInactiveDays)))
            ->chunkById(200, function ($listings) use ($change) {
                foreach ($listings as $listing) {
                    $change($listing, ListingStatus::Inactive, Actor::system());
                    $listing->customer->notify(Notices::listingInactive($listing));
                }
            });
    }
}
