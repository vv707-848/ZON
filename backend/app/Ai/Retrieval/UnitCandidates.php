<?php

namespace App\Ai\Retrieval;

use App\Enums\ListingStatus;
use App\Enums\TextReview;
use App\Models\Customer;
use App\Models\ProjectUnit;
use App\Support\Blocks;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/** Unit models for sale: of published projects in the need's area, of its types, with units left and a decided text. */
final class UnitCandidates
{
    /** @return Collection<int, ProjectUnit> */
    public function for(Need $need, ?Customer $viewer): Collection
    {
        if ($need->purpose === 'rent') {
            return new Collection;
        }
        $target = $need->target();

        return ProjectUnit::query()->with('project.developer')
            ->advertisable()
            ->where('available_count', '>', 0)
            ->whereIn('text_review', [TextReview::Approved, TextReview::Edited, TextReview::Rejected, TextReview::Auto])
            ->whereHas('project', fn (Builder $q) => $q->where('status', ListingStatus::Published)->where('city', $need->city)->whereIn('neighborhood', $need->neighborhoods)
                ->when($viewer, fn (Builder $p) => $p->whereNotIn('developer_id', Blocks::hiddenFor($viewer))))
            ->when($need->types !== [], fn (Builder $q) => $q->whereIn('property_type', $need->types))
            ->when($target !== null, fn (Builder $q) => $q->orderByRaw('ABS(price - ?)', [$target]))
            ->limit((int) config('zon.ai.candidates_limit'))->get();
    }

    /** @return array<string, mixed> */
    public static function toAgent(ProjectUnit $unit): array
    {
        return [
            'id' => $unit->id,
            'type' => $unit->property_type->value,
            'neighborhood' => $unit->project->neighborhood,
            'price' => (float) $unit->price,
            'area' => ($a = $unit->area ?? $unit->area_built ?? $unit->area_land) === null ? null : (float) $a,
            'bedrooms' => $unit->bedrooms,
            'bathrooms' => $unit->bathrooms,
            'desc' => mb_substr($unit->publicDescription(), 0, 600),
            'model' => $unit->model_name,
            'project_id' => $unit->project_id,
        ];
    }
}
