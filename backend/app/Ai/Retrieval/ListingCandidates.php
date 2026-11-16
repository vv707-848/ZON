<?php

namespace App\Ai\Retrieval;

use App\Models\Customer;
use App\Models\Listing;
use App\Support\Blocks;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Listing candidates in SQL (the agents service never adds one): published, same city, same purpose; not the
 * asker's own, not a blocked account; the closest price first, then the newest; at most 300. «مطابق» is one of
 * the neighbourhoods and one of the types; «توسيع الإدراك» is the whole city (broaden).
 */
final class ListingCandidates
{
    /** @return Collection<int, Listing> */
    public function matched(Need $need, ?Customer $viewer): Collection
    {
        return $this->base($need, $viewer)
            ->when($need->types !== [], fn (Builder $q) => $q->whereIn('property_type', $need->types))
            ->limit((int) config('zon.ai.candidates_limit'))->get();
    }

    /**
     * «توسيع الإدراك» of the matching: what the seeker's money buys anywhere in the city — any neighbourhood and
     * any type, within the top of the budget, and not smaller than the area the wish states (less the share
     * `zon.ai.broaden_area_share` forgives; an offer with no area is not shown to have it). Never an offer of
     * «مطابق». Only when a top is given: the list is about the budget.
     *
     * @param  list<int>  $except
     * @return Collection<int, Listing>
     */
    public function broaden(Need $need, ?Customer $viewer, array $except): Collection
    {
        if ($need->budgetMax === null) {
            return new Collection;
        }

        return $this->base($need, $viewer, wholeCity: true)
            ->where('price', '<=', $need->budgetMax)->whereKeyNot($except)
            ->when($need->area !== null, fn (Builder $q) => $q->whereRaw('COALESCE(area_built, area_land) >= ?', [$need->area * (float) config('zon.ai.broaden_area_share')]))
            ->limit((int) config('zon.ai.candidates_limit'))->get();
    }

    /** @return array<string, mixed> a listing as the ranking agents see it (contract §3.1) */
    public static function toAgent(Listing $listing): array
    {
        return [
            'id' => $listing->id,
            'type' => $listing->property_type->value,
            'neighborhood' => $listing->neighborhood,
            'price' => (float) $listing->price,
            'area' => $listing->area_built !== null ? (float) $listing->area_built : ($listing->area_land !== null ? (float) $listing->area_land : null),
            'bedrooms' => $listing->bedrooms,
            'bathrooms' => $listing->bathrooms,
            'age' => $listing->age,
            'deed' => $listing->has_deed,
            'bank' => $listing->bank_accepted,
            'desc' => mb_substr($listing->publicDescription(), 0, 600),
        ];
    }

    /** @return Builder<Listing> */
    private function base(Need $need, ?Customer $viewer, bool $wholeCity = false): Builder
    {
        $target = $need->target();

        return Listing::query()->live()->with(['customer', 'media' => fn ($q) => $q->where('is_cover', true)])
            ->where('city', $need->city)->when(! $wholeCity, fn (Builder $q) => $q->whereIn('neighborhood', $need->neighborhoods))
            ->when($need->purpose, fn (Builder $q, $purpose) => $q->where('purpose', $purpose))
            ->whereNotIn('customer_id', Blocks::hiddenFor($viewer))
            ->when($viewer, fn (Builder $q) => $q->where('customer_id', '!=', $viewer->id))
            ->when($target !== null, fn (Builder $q) => $q->orderByRaw('ABS(price - ?)', [$target]))
            ->orderByDesc('published_at');
    }
}
