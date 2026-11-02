<?php

namespace App\Jobs\Scheduled;

use App\Enums\ListingStatus;
use App\Jobs\ScheduledJob;
use App\Models\BuyerPreference;
use App\Models\Listing;
use App\Notifications\Notices;
use App\Support\Settings\Setting;
use App\Support\Settings\Settings;
use Illuminate\Support\Facades\Cache;

/**
 * Twice a day (09:00 · 18:00): each live wish hears how many listings published since its last look match it —
 * same city, one of its neighbourhoods and types, within its budget's top. One notice per wish and run.
 */
class NotifyWishMatches extends ScheduledJob
{
    public function __construct()
    {
        $this->onQueue('notify');
    }

    public function handle(): void
    {
        BuyerPreference::query()->with('customer')->where('is_active', true)
            ->where('updated_at', '>', now()->subDays(Settings::int(Setting::PreferenceActiveDays)))
            ->chunkById(500, function ($wishes) {
                foreach ($wishes as $wish) {
                    $since = max($wish->matches_seen_at->timestamp ?? 0, (int) Cache::get("wish-checked:{$wish->id}", $wish->created_at->timestamp ?? 0));
                    $count = Listing::query()->where('status', ListingStatus::Published)->where('city', $wish->city)
                        ->whereIn('neighborhood', $wish->neighborhoods)->where('customer_id', '!=', $wish->customer_id)
                        ->when($wish->property_types, fn ($q, $types) => $q->whereIn('property_type', $types))
                        ->when($wish->budget_max, fn ($q, $max) => $q->where('price', '<=', $max))
                        ->where('purpose', $wish->purpose)->where('published_at', '>', date('Y-m-d H:i:s', $since))->count();
                    Cache::forever("wish-checked:{$wish->id}", now()->timestamp);
                    if ($count > 0 && $wish->customer->anonymized_at === null) {
                        $wish->customer->notify(Notices::matches(min($count, max(1, Settings::int(Setting::MatchNotifyMax)))));
                    }
                }
            });
    }
}
