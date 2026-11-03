<?php

namespace App\Actions\Projects;

use App\Ai\Capture\TrainingCapture;
use App\Enums\ErrorCode;
use App\Enums\ReviewDecision;
use App\Enums\TextReview;
use App\Exceptions\ApiException;
use App\Indexing\IndexSync;
use App\Models\Project;
use App\Models\ProjectUnit;
use Illuminate\Support\Facades\DB;

/** The developer's two decisions: on the project's wording, then on his unit models' (one call for all units). */
final class DecideProjectTexts
{
    public function __construct(private readonly IndexSync $index) {}

    public function project(Project $project, ReviewDecision $decision, ?string $text): Project
    {
        DB::transaction(function () use ($project, $decision, $text) {
            $this->apply($project, $decision, $text);
            foreach ($project->units()->get() as $unit) {
                $unit->setRelation('project', $project);
                $this->index->unit($unit);
            }
        });

        return $project;
    }

    /** @param list<array{unit_id: int, decision: string, text?: ?string}> $decisions */
    public function units(Project $project, array $decisions): void
    {
        DB::transaction(function () use ($project, $decisions) {
            $units = $project->units()->get()->keyBy('id');
            foreach ($decisions as $row) {
                $unit = $units[(int) $row['unit_id']] ?? throw ApiException::of(ErrorCode::NotFound);
                $this->apply($unit, ReviewDecision::from($row['decision']), $row['text'] ?? null);
                $unit->setRelation('project', $project);
                $this->index->unit($unit);
            }
        });
    }

    private function apply(Project|ProjectUnit $record, ReviewDecision $decision, ?string $text): void
    {
        if ($record->desc_suggested === null || ! in_array($record->text_review, [TextReview::Ready, TextReview::Auto], true)) {
            throw ApiException::of(ErrorCode::InvalidTransition);
        }
        [$clean, $review] = match ($decision) {
            ReviewDecision::Approve => [$record->desc_suggested, TextReview::Approved],
            ReviewDecision::Edit => [trim((string) $text), TextReview::Edited],
            ReviewDecision::Reject => [null, TextReview::Rejected],
        };
        if ($decision === ReviewDecision::Edit) {
            TrainingCapture::textEdit((string) $record->desc_suggested, $clean, $record instanceof Project ? $record->developer : $record->project->developer);
        }
        $record->forceFill(['desc_clean' => $clean, 'text_review' => $review] + ($record instanceof Project ? ['text_reviewed_at' => now()] : []))->save();
    }
}
