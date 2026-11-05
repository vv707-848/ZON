<?php

namespace App\Support\Site;

use App\Enums\PropertyType;
use App\Enums\Purpose;
use App\Models\City;
use App\Models\Customer;
use App\Models\Listing;
use App\Models\Project;
use App\Support\Blocks;
use Illuminate\Database\Eloquent\Builder;

/**
 * The website's listings: what a guest may see (the API's guest feed, PublicView), filtered by place, kind and
 * purpose. Every filtered page has ONE address (canonical): unknown or empty values are dropped and the order is
 * fixed, so search engines never index the same page twice (ListingPageController redirects to it).
 */
final class Browse
{
    public const PER_PAGE = 24;

    public const SORTS = ['new' => 'الأحدث', 'price_asc' => 'الأقل سعراً', 'price_desc' => 'الأعلى سعراً'];

    /** @return Builder<Listing> live listings a guest may see, the featured first */
    public static function listings(?Customer $viewer = null): Builder
    {
        return Listing::query()->live()->with(['customer', 'media' => fn ($q) => $q->where('is_cover', true)])
            ->whereNotIn('customer_id', Blocks::hiddenFor($viewer));
    }

    /** @return Builder<Project> published projects a guest may see */
    public static function projects(?Customer $viewer = null): Builder
    {
        return Project::query()->published()->with(['developer', 'media' => fn ($q) => $q->where('is_cover', true), 'units.licence'])
            ->whereNotIn('developer_id', Blocks::hiddenFor($viewer));
    }

    /**
     * The filters of a query string, kept only when valid, in the canonical order.
     *
     * @param  array<string, mixed>  $query
     * @return array{city?: string, neighborhood?: string, type?: string, purpose?: string, sort?: string, page?: int}
     */
    public static function filters(array $query): array
    {
        $value = fn (string $key) => is_string($query[$key] ?? null) ? trim($query[$key]) : '';
        $filters = [];
        if (City::has($value('city'))) {
            $filters['city'] = $value('city');
            if ($value('neighborhood') !== '' && City::has($filters['city'], $value('neighborhood'))) {
                $filters['neighborhood'] = $value('neighborhood');
            }
        }
        if (PropertyType::tryFrom($value('type')) !== null) {
            $filters['type'] = $value('type');
        }
        if (Purpose::tryFrom($value('purpose')) !== null) {
            $filters['purpose'] = $value('purpose');
        }
        if (isset(self::SORTS[$value('sort')]) && $value('sort') !== 'new') {
            $filters['sort'] = $value('sort');
        }
        $page = filter_var($query['page'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 2]]);
        if ($page !== false) {
            $filters['page'] = (int) $page;
        }

        return $filters;
    }

    /**
     * @param  Builder<Listing>  $query
     * @param  array{city?: string, neighborhood?: string, type?: string, purpose?: string, sort?: string, page?: int}  $filters
     * @return Builder<Listing>
     */
    public static function apply(Builder $query, array $filters): Builder
    {
        $query->when($filters['city'] ?? null, fn (Builder $q, $v) => $q->where('city', $v))
            ->when($filters['neighborhood'] ?? null, fn (Builder $q, $v) => $q->where('neighborhood', $v))
            ->when($filters['type'] ?? null, fn (Builder $q, $v) => $q->where('property_type', $v))
            ->when($filters['purpose'] ?? null, fn (Builder $q, $v) => $q->where('purpose', $v));

        return match ($filters['sort'] ?? 'new') {
            'price_asc' => $query->orderBy('price')->orderByDesc('id'),
            'price_desc' => $query->orderByDesc('price')->orderByDesc('id'),
            default => $query->orderByRaw('featured_until IS NOT NULL AND featured_until > ? DESC', [now()])->orderByDesc('published_at')->orderByDesc('id'),
        };
    }

    /**
     * «شقق للبيع في النرجس، الرياض» — the page's title and heading from its filters.
     *
     * @param  array{city?: string, neighborhood?: string, type?: string, purpose?: string}  $filters
     */
    public static function title(array $filters): string
    {
        $type = isset($filters['type']) ? PropertyType::from($filters['type'])->plural() : 'عقارات';
        $purpose = isset($filters['purpose']) ? ' '.Purpose::from($filters['purpose'])->label() : '';
        $place = match (true) {
            isset($filters['neighborhood'], $filters['city']) => " في {$filters['neighborhood']}، {$filters['city']}",
            isset($filters['city']) => " في {$filters['city']}",
            default => ' في السعودية',
        };

        return $type.$purpose.$place;
    }

    /** @param array<string, string|int> $filters */
    public static function url(array $filters): string
    {
        return Site::url('listings').($filters === [] ? '' : '?'.http_build_query($filters));
    }
}
