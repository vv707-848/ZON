<?php

namespace App\Jobs;

use App\Ai\AiGateway;
use App\Enums\AiAgent;
use App\Enums\TextReview;
use App\Indexing\IndexSync;
use App\Models\Project;
use App\Models\ProjectUnit;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;

/**
 * The second review: every unit model still waiting, in ONE call (clean_units). Ids the model invents are dropped;
 * a unit the AI could not word keeps its own text (auto) and becomes searchable at once.
 */
class CleanUnitTexts implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $uniqueFor = 60;

    public function __construct(public readonly int $projectId)
    {
        $this->onQueue('ai');
    }

    public function uniqueId(): string
    {
        return (string) $this->projectId;
    }

    public function handle(AiGateway $ai, IndexSync $index): void
    {
        $project = Project::query()->with('developer')->find($this->projectId);
        $units = $project?->units()->where('text_review', TextReview::Pending)->get() ?? collect();
        if ($project === null || $units->isEmpty()) {
            return;
        }

        $result = $ai->call(AiAgent::CleanUnits, [
            'project_type' => $project->property_type->value,
            'units' => $units->map(fn (ProjectUnit $u) => self::asked($u))->values()->all(),
        ], check: function (array $out) use ($units): ?array {
            $texts = [];
            foreach ((array) ($out['units'] ?? []) as $row) {
                $id = (int) ($row['id'] ?? 0);
                if ($units->contains('id', $id) && trim((string) ($row['desc_clean'] ?? '')) !== '') {
                    $texts[$id] = trim((string) $row['desc_clean']);
                }
            }

            return $texts === [] ? null : ['texts' => $texts];
        }, customer: $project->developer, cache: false);

        $texts = $result->output['texts'] ?? [];
        DB::transaction(function () use ($units, $texts, $project, $index) {
            // each unit held again: one edited while the AI answered is left to its own wording
            $fresh = ProjectUnit::query()->whereKey($units->pluck('id')->all())->lockForUpdate()->get()->keyBy('id');
            foreach ($units as $asked) {
                $unit = $fresh->get($asked->id);
                if ($unit === null || $unit->text_review !== TextReview::Pending || self::asked($unit) !== self::asked($asked)) {
                    continue;
                }
                $unit->setRelation('project', $project);
                if (isset($texts[$unit->id])) {
                    $unit->forceFill(['desc_suggested' => $texts[$unit->id], 'text_review' => TextReview::Ready])->save();
                } else {
                    $unit->forceFill(['text_review' => TextReview::Auto])->save();
                    $index->unit($unit);
                }
            }
        });
    }

    /**
     * What the AI is asked about [unit].
     *
     * @return array<string, mixed>
     */
    private static function asked(ProjectUnit $u): array
    {
        return array_filter([
            'id' => $u->id, 'model' => $u->model_name, 'type' => $u->property_type->value, 'area' => $u->area === null ? null : (float) $u->area,
            'bedrooms' => $u->bedrooms, 'bathrooms' => $u->bathrooms, 'halls' => $u->halls, 'text' => $u->desc_raw,
        ], fn ($v) => $v !== null);
    }
}
