<?php

namespace App\Jobs\Scheduled;

use App\Enums\ListingStatus;
use App\Jobs\ScheduledJob;
use App\Models\AdLicense;
use App\Models\Listing;
use App\Models\ProjectUnit;
use App\Notifications\Notices;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * A week before a licence's last day, and again on the day before it, the advertiser (or the developer, for a
 * unit) is told to renew — each warning once (`ad_licenses.reminded_days`), even if a run was missed.
 */
class RemindLicenceExpiry extends ScheduledJob
{
    /** Days left when each warning is due: the first, then the last. */
    public const WEEK = 7;

    public const DAY = 1;

    public function handle(): void
    {
        $until = CarbonImmutable::parse(AdLicense::today())->addDays(self::WEEK)->toDateString();
        $soon = fn (Builder $licence) => AdLicense::whereCurrent($licence)->where('expires_at', '<=', $until);

        Listing::query()->whereIn('status', [ListingStatus::Published, ListingStatus::Paused, ListingStatus::Inactive])
            ->whereHas('licence', $soon)->with(['licence', 'customer'])
            ->chunkById(200, function ($listings) {
                foreach ($listings as $listing) {
                    $stage = self::due($listing->licence);
                    if ($stage !== null) {
                        $listing->customer->notify(Notices::licenceExpiring($listing, (int) $listing->licence->daysLeft(), $stage));
                        $listing->licence->forceFill(['reminded_days' => $stage])->save();
                    }
                }
            });

        ProjectUnit::query()->whereHas('project', fn (Builder $p) => $p->where('status', ListingStatus::Published))
            ->whereHas('licence', $soon)->with(['licence', 'project.developer'])
            ->chunkById(200, function ($units) {
                foreach ($units as $unit) {
                    $stage = self::due($unit->licence);
                    if ($stage !== null) {
                        $unit->project->developer->notify(Notices::unitLicenceExpiring($unit, (int) $unit->licence->daysLeft(), $stage));
                        $unit->licence->forceFill(['reminded_days' => $stage])->save();
                    }
                }
            });
    }

    /** The warning owed now and not sent yet: the week's within seven days, the last within one. */
    private static function due(AdLicense $licence): ?int
    {
        $stage = $licence->daysLeft() <= self::DAY ? self::DAY : self::WEEK;

        return $licence->reminded_days === null || $licence->reminded_days > $stage ? $stage : null;
    }
}
