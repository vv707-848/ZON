<?php

namespace App\Actions\Projects;

use App\Enums\CustomerRole;
use App\Enums\Delivery;
use App\Enums\ErrorCode;
use App\Enums\ListingStatus;
use App\Exceptions\ApiException;
use App\Models\Project;
use App\Notifications\Notices;
use App\Support\Actor;
use App\Support\PublishingGate;

/**
 * Publishing a project: the developer's gates, a decided project text, at least one unit model
 * (PROJECT_NO_UNITS), and for a ready project a valid licence on every model (LICENCE_REQUIRED). Units still
 * waiting for their wording are published unsearchable until decided (or the 72 h rule).
 */
final class PublishProject
{
    public function __construct(private readonly ChangeProjectStatus $status) {}

    /** The first publication: only a project that was never public (a hidden or rejected one belongs to moderation). */
    public function __invoke(Project $project): Project
    {
        if (! $project->status->isBeforePublication()) {
            throw ApiException::of(ErrorCode::InvalidTransition, ['from' => $project->status->value, 'to' => ListingStatus::Published->value]);
        }

        return $this->publish($project);
    }

    /** «استئناف» after the developer's own pause: every requirement again — units may have changed meanwhile. */
    public function resume(Project $project): Project
    {
        if (! $project->status->canBeResumedByAdvertiser()) {
            throw ApiException::of(ErrorCode::InvalidTransition, ['from' => $project->status->value, 'to' => ListingStatus::Published->value]);
        }

        return $this->publish($project);
    }

    private function publish(Project $project): Project
    {
        PublishingGate::assert($project->developer, CustomerRole::Developer);
        if (! $project->textDecided()) {
            throw ApiException::of(ErrorCode::InvalidTransition, ['reason' => 'text_review']);
        }
        $units = $project->units()->with('licence')->get();
        if ($units->isEmpty()) {
            throw ApiException::of(ErrorCode::ProjectNoUnits);
        }
        if ($project->delivery === Delivery::Ready && $units->contains(fn ($unit) => ! ($unit->licence?->isValid() ?? false))) {
            throw ApiException::of(ErrorCode::LicenceRequired, ['units' => $units->filter(fn ($u) => ! ($u->licence?->isValid() ?? false))->pluck('id')->values()]);
        }

        $first = $project->published_at === null;
        ($this->status)($project, ListingStatus::Published, Actor::customer($project->developer));
        if ($first) {
            $project->developer->notify(Notices::projectPublished($project));
        }

        return $project;
    }
}
