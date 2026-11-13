<?php

namespace App\Jobs\Scheduled;

use App\Enums\BoostKind;
use App\Indexing\IndexSync;
use App\Jobs\ScheduledJob;
use App\Models\BoostUsage;
use App\Models\Listing;
use App\Models\Project;
use App\Models\SearchRequest;
use App\Notifications\Notices;

/** A finished boost: the advertiser hears of it once; an urgent request goes back to normal. */
class EndBoosts extends ScheduledJob
{
    public function handle(): void
    {
        $index = app(IndexSync::class);
        BoostUsage::query()->with('target')->whereNull('ended_notified_at')->whereNotNull('active_until')->where('active_until', '<=', now())
            ->chunkById(200, function ($boosts) use ($index) {
                foreach ($boosts as $boost) {
                    $target = $boost->target;
                    if ($target instanceof SearchRequest && $boost->kind === BoostKind::UrgentRequest) {
                        $target->forceFill(['urgent' => false])->save();
                        $index->request($target);
                    } elseif ($target instanceof Listing && $target->customer !== null) {
                        $target->customer->notify(Notices::boostEnded($target, $boost->kind));
                    } elseif ($target instanceof Project && $target->loadMissing('developer')->developer !== null) {
                        $target->developer->notify(Notices::projectBoostEnded($target));
                    }
                    $boost->forceFill(['ended_notified_at' => now()])->save();
                }
            });
    }
}
