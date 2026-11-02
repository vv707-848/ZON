<?php

namespace App\Jobs\Scheduled;

use App\Enums\ListingStatus;
use App\Enums\TextReview;
use App\Jobs\ScheduledJob;
use App\Models\Listing;
use App\Notifications\Notices;
use App\Support\Settings\Setting;
use App\Support\Settings\Settings;
use Illuminate\Support\Facades\Cache;

/** The advertiser is reminded once, Setting::ReviewReminderHours after the wording was ready. */
class RemindPendingReviews extends ScheduledJob
{
    public function handle(): void
    {
        Listing::query()->with('customer')->where('status', ListingStatus::PendingReview)->where('text_review', TextReview::Ready)
            ->whereHas('history', fn ($q) => $q->where('field', 'status')->where('new_value', ListingStatus::PendingReview->value)
                ->where('changed_at', '<=', now()->subHours(Settings::int(Setting::ReviewReminderHours))))
            ->chunkById(200, function ($listings) {
                foreach ($listings as $listing) {
                    if (Cache::add("review-reminded:{$listing->id}", true, now()->addDays(10))) {
                        $listing->customer->notify(Notices::wordingReminder($listing));
                    }
                }
            });
    }
}
