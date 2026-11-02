<?php

namespace App\Jobs\Scheduled;

use App\Actions\Listings\ChangeListingStatus;
use App\Enums\LicenceStatus;
use App\Enums\ListingStatus;
use App\Indexing\IndexSync;
use App\Jobs\ScheduledJob;
use App\Models\AdLicense;
use App\Models\Listing;
use App\Models\ProjectUnit;
use App\Notifications\Notices;
use App\Support\Actor;
use Illuminate\Database\Eloquent\Builder;

/**
 * Records what midnight in Riyadh already did. A listing whose licence passed its last day left the public at
 * once (Listing::live); here it becomes «منتهي الترخيص» (out of the search index) and its advertiser hears of
 * it. A unit model of a ready project leaves the index the same way. Every licence past its date is «expired».
 */
class ExpireLicences extends ScheduledJob
{
    /** The statuses an advertiser can bring back by himself — they move; sold, hidden and rejected stay as they are. */
    private const OPEN = [ListingStatus::Published, ListingStatus::Paused, ListingStatus::Inactive];

    public function handle(): void
    {
        $change = app(ChangeListingStatus::class);
        $index = app(IndexSync::class);
        $ended = fn (Builder $licence) => $licence->where('status', LicenceStatus::Valid)->where('expires_at', '<', AdLicense::today());

        Listing::query()->whereIn('status', self::OPEN)->whereHas('licence', $ended)->with(['licence', 'customer'])
            ->chunkById(200, function ($listings) use ($change) {
                foreach ($listings as $listing) {
                    $listing->licence->forceFill(['status' => LicenceStatus::Expired])->save();
                    $change($listing, ListingStatus::LicenceExpired, Actor::system());
                    $listing->customer->notify(Notices::licenceExpired($listing));
                }
            });

        ProjectUnit::query()->whereHas('licence', $ended)->with(['licence', 'project.developer'])
            ->chunkById(200, function ($units) use ($index) {
                foreach ($units as $unit) {
                    $unit->licence->forceFill(['status' => LicenceStatus::Expired])->save();
                    $index->unit($unit);
                    if ($unit->project->status === ListingStatus::Published) {
                        $unit->project->developer->notify(Notices::unitLicenceExpired($unit));
                    }
                }
            });

        // What is left: earlier licences of a listing or a unit, and those of sold, hidden or rejected listings.
        AdLicense::query()->where('status', LicenceStatus::Valid)->where('expires_at', '<', AdLicense::today())
            ->update(['status' => LicenceStatus::Expired]);
    }
}
