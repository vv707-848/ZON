<?php

namespace App\Actions\Projects;

use App\Enums\CustomerRole;
use App\Enums\TextReview;
use App\Indexing\IndexSync;
use App\Jobs\CleanProjectText;
use App\Models\Customer;
use App\Models\Project;
use App\Support\MediaLibrary;
use App\Support\PublishingGate;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

/** Creates or edits a project. A new description goes back to the AI wording; units are re-indexed with it. */
final class SaveProject
{
    public function __construct(private readonly IndexSync $index) {}

    /**
     * @param  array<string, mixed>  $columns
     * @param  ?list<int>  $mediaIds
     */
    public function __invoke(Customer $developer, ?Project $project, array $columns, ?array $mediaIds, ?int $coverId): Project
    {
        PublishingGate::assert($developer, CustomerRole::Developer);

        $newText = false;
        $saved = DB::transaction(function () use ($developer, $project, $columns, $mediaIds, $coverId, &$newText) {
            $newText = $project === null || (isset($columns['desc_raw']) && $columns['desc_raw'] !== $project->desc_raw);
            $project ??= new Project(['developer_id' => $developer->id]);
            $project->fill($columns);
            if ($newText) {
                $project->forceFill(['desc_clean' => null, 'desc_suggested' => null, 'text_review' => TextReview::Pending]);
            }
            $project->save();

            if ($mediaIds !== null) {
                MediaLibrary::sync($project, $developer, $mediaIds, $coverId);
            }
            foreach ($project->units()->get() as $unit) {
                $unit->setRelation('project', $project);
                $this->index->unit($unit);           // city, neighbourhood and context travel with every unit
            }

            return $project;
        });
        if ($newText) {
            // after the save: a queue that cannot take it fails nothing saved (RequeueStuckWording sends it again)
            rescue(fn () => Bus::dispatch(new CleanProjectText($saved->id)), report: true);
        }

        return $saved;
    }
}
