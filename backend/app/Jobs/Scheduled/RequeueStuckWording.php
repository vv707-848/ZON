<?php

namespace App\Jobs\Scheduled;

use App\Enums\TextReview;
use App\Jobs\CleanListingText;
use App\Jobs\CleanProjectText;
use App\Jobs\CleanUnitTexts;
use App\Jobs\ScheduledJob;
use App\Models\Listing;
use App\Models\Project;
use App\Models\ProjectUnit;
use Illuminate\Support\Facades\Bus;

/**
 * A listing, project or unit whose wording was never sent (the queue could not take it when it was saved:
 * CreateListing, UpdateListing, SaveProject, SaveUnit) would wait «قيد الصياغة» for ever: after a quarter of an
 * hour without it, it is sent again. The wording jobs do nothing to a text already decided, so a second send
 * changes nothing.
 */
class RequeueStuckWording extends ScheduledJob
{
    public const AFTER_MINUTES = 15;

    public function handle(): void
    {
        Listing::query()->where('text_review', TextReview::Pending)->where('updated_at', '<=', now()->subMinutes(self::AFTER_MINUTES))
            ->select('id')->chunkById(200, fn ($listings) => $listings->each(fn (Listing $l) => Bus::dispatch(new CleanListingText($l->id))));
        Project::query()->where('text_review', TextReview::Pending)->where('updated_at', '<=', now()->subMinutes(self::AFTER_MINUTES))
            ->select('id')->chunkById(200, fn ($projects) => $projects->each(fn (Project $p) => Bus::dispatch(new CleanProjectText($p->id))));
        ProjectUnit::query()->where('text_review', TextReview::Pending)->where('updated_at', '<=', now()->subMinutes(self::AFTER_MINUTES))
            ->distinct()->pluck('project_id')->each(fn (int $project) => CleanUnitTexts::sendFor($project));   // one call per project
    }
}
