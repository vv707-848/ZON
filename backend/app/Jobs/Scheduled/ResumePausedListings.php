<?php

namespace App\Jobs\Scheduled;

use App\Actions\Listings\ChangeListingStatus;
use App\Enums\ListingStatus;
use App\Jobs\ScheduledJob;
use App\Models\Listing;
use App\Support\Actor;

/** A pause with an end date ends by itself. */
class ResumePausedListings extends ScheduledJob
{
    public function handle(): void
    {
        $change = app(ChangeListingStatus::class);
        Listing::query()->where('status', ListingStatus::Paused)->whereNotNull('paused_until')->where('paused_until', '<=', now())
            ->chunkById(200, fn ($listings) => $listings->each(fn (Listing $l) => $change($l, ListingStatus::Published, Actor::system())));
    }
}
