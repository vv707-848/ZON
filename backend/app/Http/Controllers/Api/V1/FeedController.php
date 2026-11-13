<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ListingResource;
use App\Http\Resources\ProjectResource;
use App\Models\Customer;
use App\Models\Listing;
use App\Models\Project;
use App\Models\ProjectUnit;
use App\Support\Api;
use App\Support\Blocks;
use App\Support\Favorites;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The app's home feed («الرئيسية»): listings and projects in one list, filtered and sorted as its filter card
 * offers — purpose, city, neighbourhood, source, property type; newest, oldest, featured only, price or area either
 * way, with what is featured now first in the newest and oldest orders. A project's price is its lowest available
 * unit that may be advertised (its «ابتداءً من»); it is for sale only, and its source is the developer. Rows the
 * viewer must not see (blocks, the sandbox) are left out as in /listings and /projects; each row says its `kind`.
 */
class FeedController extends Controller
{
    public const SORTS = ['new', 'old', 'featured', 'price_asc', 'price_desc', 'area_asc', 'area_desc'];

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'purpose' => ['nullable', 'in:sale,rent'],
            'city' => ['nullable', 'string', 'max:80'],
            'neighborhood' => ['nullable', 'string', 'max:80'],
            'source' => ['nullable', 'in:broker,owner,developer'],
            'property_type' => ['nullable', 'string', 'max:12'],
            'sort' => ['nullable', 'in:'.implode(',', self::SORTS)],
            'per_page' => ['nullable', 'integer', 'between:5,50'],
        ]);
        $viewer = $request->user('api');
        $viewer = $viewer instanceof Customer ? $viewer : null;
        $hidden = Blocks::hiddenFor($viewer);
        $now = now();
        $sort = $filters['sort'] ?? 'new';

        $listings = Listing::query()->live()->whereNotIn('customer_id', $hidden)
            ->when($filters['purpose'] ?? null, fn (Builder $q, $v) => $q->where('purpose', $v))
            ->when($filters['city'] ?? null, fn (Builder $q, $v) => $q->where('city', $v))
            ->when($filters['neighborhood'] ?? null, fn (Builder $q, $v) => $q->where('neighborhood', $v))
            ->when($filters['property_type'] ?? null, fn (Builder $q, $v) => $q->where('property_type', $v))
            ->when($filters['source'] ?? null, fn (Builder $q, $v) => $q->whereHas('customer', fn (Builder $c) => $c->where('role', $v)))
            ->when($sort === 'featured', fn (Builder $q) => $q->where('featured_until', '>', $now))
            ->toBase()->select([DB::raw("'listing' AS kind"), 'listings.id', 'listings.price AS sort_price',
                DB::raw('COALESCE(listings.area_built, listings.area_land) AS sort_area'), 'listings.published_at',
                DB::raw('(listings.featured_until IS NOT NULL AND listings.featured_until > ?) AS is_featured')])
            ->addBinding([$now], 'select');

        $startPrice = ProjectUnit::query()->advertisable()->where('available_count', '>', 0)->whereColumn('project_units.project_id', 'projects.id')
            ->toBase()->selectRaw('MIN(project_units.price)');
        $projects = Project::query()->published()->whereNotIn('developer_id', $hidden)
            ->when($filters['city'] ?? null, fn (Builder $q, $v) => $q->where('city', $v))
            ->when($filters['neighborhood'] ?? null, fn (Builder $q, $v) => $q->where('neighborhood', $v))
            ->when($filters['property_type'] ?? null, fn (Builder $q, $v) => $q->where('property_type', $v))
            ->when($sort === 'featured', fn (Builder $q) => $q->where('featured_until', '>', $now))
            ->toBase()->select([DB::raw("'project' AS kind"), 'projects.id'])->selectSub($startPrice, 'sort_price')
            ->addSelect([DB::raw('NULL AS sort_area'), 'projects.published_at', DB::raw('(projects.featured_until IS NOT NULL AND projects.featured_until > ?) AS is_featured')])
            ->addBinding([$now], 'select');

        $withProjects = ($filters['purpose'] ?? 'sale') === 'sale' && in_array($filters['source'] ?? 'developer', ['developer'], true);
        $withListings = ($filters['source'] ?? null) !== 'developer';
        $union = match (true) {
            $withListings && $withProjects => $listings->unionAll($projects),
            $withProjects => $projects,
            default => $listings,
        };
        $feed = DB::query()->fromSub($union, 'feed');
        match ($sort) {
            'price_asc', 'price_desc' => $feed->orderByRaw('sort_price IS NULL')->orderBy('sort_price', $sort === 'price_asc' ? 'asc' : 'desc'),
            'area_asc', 'area_desc' => $feed->orderByRaw('sort_area IS NULL')->orderBy('sort_area', $sort === 'area_asc' ? 'asc' : 'desc'),
            'old' => $feed->orderByDesc('is_featured')->orderBy('published_at'),
            default => $feed->orderByDesc('is_featured')->orderByDesc('published_at'),
        };
        $page = $feed->orderBy('kind')->orderByDesc('id')->paginate((int) ($filters['per_page'] ?? 18));

        $rows = collect($page->items());
        $ids = fn (string $kind) => $rows->where('kind', $kind)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $listingModels = Listing::query()->with(['customer', 'media' => fn ($q) => $q->where('is_cover', true)])->whereKey($ids('listing'))->get()->keyBy('id');
        $projectModels = Project::query()->with(['developer', 'media' => fn ($q) => $q->where('is_cover', true), 'units.licence'])->whereKey($ids('project'))->get()->keyBy('id');
        $projectModels->each(fn (Project $p) => $p->setRelation('units', $p->units->filter(fn (ProjectUnit $u) => $u->isAdvertisable($p))->values()));
        Favorites::mark($request, 'listing', $ids('listing'));
        Favorites::mark($request, 'project', $ids('project'));

        $items = $rows->map(fn (object $row) => $row->kind === 'listing'
            ? ['kind' => 'listing', ...(new ListingResource($listingModels[(int) $row->id]))->resolve($request)]
            : ['kind' => 'project', ...(new ProjectResource($projectModels[(int) $row->id]))->resolve($request)])->values()->all();

        return Api::ok($items, ['page' => [
            'current' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last' => $page->lastPage(),
        ]]);
    }
}
