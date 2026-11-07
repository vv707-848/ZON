<?php

namespace App\Actions\Projects;

use App\Enums\ErrorCode;
use App\Enums\ListingStatus;
use App\Exceptions\ApiException;
use App\Indexing\IndexSync;
use App\Models\Project;
use App\Support\Actor;
use Illuminate\Support\Facades\DB;

/**
 * The same state machine as listings; a project's status decides whether each of its unit models is searchable.
 * Nothing is published while the developer's account is being deleted: it stays hidden, and a cancellation
 * publishes it (as ChangeListingStatus).
 */
final class ChangeProjectStatus
{
    public function __construct(private readonly IndexSync $index) {}

    /** @param array<string, mixed> $also other columns saved with the change (auto_hidden_at) */
    public function __invoke(Project $project, ListingStatus $to, Actor $actor, array $also = []): Project
    {
        if ($to === ListingStatus::Published && ($deletion = $project->developer?->pendingDeletion) !== null) {
            $deletion->keepHidden('projects', $project->id);
            $to = ListingStatus::Hidden;
        }
        $from = $project->status;
        if ($from === $to) {
            return $project;
        }
        if (! $from->canBecome($to)) {
            throw ApiException::of(ErrorCode::InvalidTransition, ['from' => $from->value, 'to' => $to->value]);
        }

        return DB::transaction(function () use ($project, $from, $to, $actor, $also) {
            $project->forceFill([
                'status' => $to,
                'published_at' => $to === ListingStatus::Published ? ($project->published_at ?? now()) : $project->published_at,
                'auto_hidden_at' => $also['auto_hidden_at'] ?? null,     // a person's decision replaces the reports'
            ])->save();
            activity('projects')->performedOn($project)->event('status')
                ->withProperties(['from' => $from->value, 'to' => $to->value, 'actor' => (string) $actor])->log('تغيير حالة المشروع');

            foreach ($project->units()->get() as $unit) {
                $unit->setRelation('project', $project);
                $this->index->unit($unit);
            }

            return $project;
        });
    }
}
