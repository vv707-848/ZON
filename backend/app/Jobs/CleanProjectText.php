<?php

namespace App\Jobs;

use App\Ai\AiGateway;
use App\Enums\AiAgent;
use App\Enums\TextReview;
use App\Models\Project;
use App\Notifications\Notices;
use App\Support\ArabicText;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;

/** The first of a project's two reviews: its own description (clean_project). Without the AI: kept as written. */
class CleanProjectText implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public readonly int $projectId)
    {
        $this->onQueue('ai');
    }

    public function handle(AiGateway $ai): void
    {
        $project = Project::query()->with('developer')->find($this->projectId);
        if ($project === null || $project->text_review !== TextReview::Pending) {
            return;
        }
        $result = $ai->call(AiAgent::CleanProject, ['type' => $project->property_type->value, 'text' => $project->desc_raw],
            check: fn (array $out) => trim((string) ($out['desc_clean'] ?? '')) === '' ? null : ['desc_clean' => trim((string) $out['desc_clean'])],
            customer: $project->developer, cache: false);

        DB::transaction(function () use ($project, $result) {
            // held again: an edit while the AI answered makes this answer another text's
            $fresh = Project::query()->with('developer')->lockForUpdate()->find($this->projectId);
            if ($fresh === null || $fresh->text_review !== TextReview::Pending || $fresh->desc_raw !== $project->desc_raw
                || $fresh->property_type !== $project->property_type) {
                return;
            }
            $this->keep($fresh, $result->output['desc_clean'] ?? null);
        });
    }

    private function keep(Project $project, ?string $suggested): void
    {
        if ($suggested === null || ArabicText::normalize($suggested) === ArabicText::normalize($project->desc_raw)) {
            $project->forceFill(['text_review' => $suggested === null ? TextReview::Auto : TextReview::Approved, 'text_reviewed_at' => now()])->save();

            return;
        }
        $project->forceFill(['desc_suggested' => $suggested, 'text_review' => TextReview::Ready])->save();
        $project->developer->notify(Notices::projectWordingReady($project));
    }
}
