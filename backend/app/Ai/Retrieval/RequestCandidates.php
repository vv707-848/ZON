<?php

namespace App\Ai\Retrieval;

use App\Enums\RequestStatus;
use App\Models\Customer;
use App\Models\SearchRequest;
use App\Support\Blocks;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/** Requests a broker's offer could answer: published, same city, sharing a neighbourhood and a type; urgent first. */
final class RequestCandidates
{
    /** @return Collection<int, SearchRequest> */
    public function for(Need $need, Customer $broker): Collection
    {
        return SearchRequest::query()->with('customer')->where('status', RequestStatus::Published)->where('city', $need->city)
            ->where('customer_id', '!=', $broker->id)->whereNotIn('customer_id', Blocks::hiddenFor($broker))
            ->where(fn (Builder $q) => self::anyOf($q, 'neighborhoods', $need->neighborhoods))
            ->when($need->types !== [], fn (Builder $q) => $q->where(fn (Builder $w) => self::anyOf($w, 'property_types', $need->types)))
            ->when($need->purpose, fn (Builder $q, $purpose) => $q->where('purpose', $purpose))
            ->orderByDesc('urgent')->latest('id')
            ->limit((int) config('zon.ai.candidates_limit'))->get();
    }

    /**
     * The JSON list contains at least one of the values.
     *
     * @param  Builder<SearchRequest>  $query
     * @param  list<string>  $values
     */
    private static function anyOf(Builder $query, string $column, array $values): void
    {
        foreach ($values as $value) {
            $query->orWhereJsonContains($column, $value);
        }
    }

    /** @return array<string, mixed> */
    public static function toAgent(SearchRequest $request): array
    {
        $number = fn ($v) => $v === null ? null : (float) $v;

        return [
            'id' => $request->id,
            'types' => array_values($request->property_types),
            'neighborhoods' => array_values($request->neighborhoods),
            'budget_min' => $number($request->budget_min),
            'budget_max' => $number($request->budget_max),
            'rooms_min' => $request->rooms_min,
            'area_min' => $number($request->area_min),
            'urgent' => $request->urgent,
            'desc' => mb_substr((string) $request->description, 0, 600),
        ];
    }
}
