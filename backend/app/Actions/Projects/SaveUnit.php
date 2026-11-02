<?php

namespace App\Actions\Projects;

use App\Enums\TextReview;
use App\Indexing\IndexSync;
use App\Jobs\CleanUnitTexts;
use App\Models\Project;
use App\Models\ProjectUnit;
use App\Support\MediaLibrary;
use Illuminate\Support\Facades\DB;

/** A unit model, new or edited; its text joins the next clean_units call of the project. */
final class SaveUnit
{
    public function __construct(private readonly IndexSync $index) {}

    /** @param array<string, mixed> $columns @param ?list<int> $mediaIds */
    public function __invoke(Project $project, ?ProjectUnit $unit, array $columns, ?array $mediaIds): ProjectUnit
    {
        return DB::transaction(function () use ($project, $unit, $columns, $mediaIds) {
            $newText = $unit === null || (isset($columns['desc_raw']) && $columns['desc_raw'] !== $unit->desc_raw);
            $unit ??= new ProjectUnit(['project_id' => $project->id]);
            $unit->fill($columns);
            if ($newText) {
                $unit->forceFill(['desc_clean' => null, 'desc_suggested' => null, 'text_review' => TextReview::Pending]);
            }
            $unit->save();
            $unit->setRelation('project', $project);

            if ($mediaIds !== null) {
                MediaLibrary::sync($unit, $project->developer, $mediaIds, null);
            }
            $newText ? CleanUnitTexts::dispatch($project->id)->afterCommit() : $this->index->unit($unit);

            return $unit;
        });
    }
}
