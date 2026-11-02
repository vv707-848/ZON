<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Listings\CreateListing;
use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Crm\InventoryRequest;
use App\Http\Requests\Api\Listings\ListingRequest;
use App\Http\Resources\MediaResource;
use App\Models\InventoryItem;
use App\Models\Listing;
use App\Services\Billing\Entitlements;
use App\Support\Api;
use App\Support\MediaLibrary;
use App\Support\Ownership;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * «المخزون»: private stock — never indexed, never listed to anyone else. «نشر كإعلان» goes through the normal
 * publishing path (the same form rules, gates and licence) with the item's photos copied, one at a time per item.
 */
class InventoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $items = $request->user('api')->inventoryItems()->with(['media' => fn ($q) => $q->where('is_cover', true)])->latest('updated_at')->limit(500)->get();

        return Api::ok($items->map(fn (InventoryItem $i) => $this->present($i)));
    }

    public function store(InventoryRequest $request): JsonResponse
    {
        $me = $request->customer();
        if (! Entitlements::for($me)->canAddInventory()) {
            throw ApiException::of(ErrorCode::PlanLimitReached);
        }
        $item = DB::transaction(function () use ($request, $me) {
            $item = $me->inventoryItems()->create($request->safe()->except('media_ids'));
            MediaLibrary::sync($item, $me, array_map('intval', $request->input('media_ids', [])));

            return $item;
        });

        return Api::created($this->present($item->load('media')));
    }

    public function show(Request $request, InventoryItem $item): JsonResponse
    {
        Ownership::assert($item, $request->user('api'));

        return Api::ok($this->present($item->load('media')) + ['photos' => MediaResource::collection($item->media)]);
    }

    public function update(InventoryRequest $request, InventoryItem $item): JsonResponse
    {
        Ownership::assert($item, $request->customer());
        $item->fill($request->safe()->except('media_ids'))->save();
        if ($request->has('media_ids')) {
            MediaLibrary::sync($item, $request->customer(), array_map('intval', $request->input('media_ids', [])));
        }

        return Api::ok($this->present($item->load('media')));
    }

    public function destroy(Request $request, InventoryItem $item): JsonResponse
    {
        Ownership::assert($item, $request->user('api'));
        $item->delete();

        return Api::ok();
    }

    /** The item becomes a listing: what the stock lacks (licence, a longer description…) comes with this call. */
    public function publish(Request $request, InventoryItem $item, CreateListing $create): JsonResponse
    {
        Ownership::assert($item, $request->user('api'));

        return DB::transaction(function () use ($request, $item, $create) {
            $item = InventoryItem::query()->lockForUpdate()->findOrFail($item->id);
            if ($item->published_listing_id !== null && Listing::query()->whereKey($item->published_listing_id)->exists()) {
                throw ApiException::of(ErrorCode::AlreadyActive);
            }

            $data = [
                'purpose' => $item->purpose->value, 'property_type' => $item->property_type->value, 'title' => $item->title,
                'city' => $item->city, 'neighborhood' => $item->neighborhood, 'price' => $item->price, 'description' => $item->description,
                ...array_filter(['area_land' => $item->area_land, 'area_built' => $item->area_built, 'bedrooms' => $item->bedrooms, 'bathrooms' => $item->bathrooms],
                    fn ($v, $field) => $v !== null && in_array($field, $item->property_type->fields(), true), ARRAY_FILTER_USE_BOTH),
                ...$request->except(['media_ids', 'cover_id', 'owner_case_id']),
            ];
            $form = ListingRequest::createFrom(Request::create($request->url(), 'POST', $data));
            $form->setContainer(app())->setRedirector(app('redirect'))->setUserResolver($request->getUserResolver());
            $form->validateResolved();

            $listing = $create($form->customer(), $form->columns(), [], null, $form->input('licence_number'), null);
            MediaLibrary::copy($item, $listing);
            $cover = $listing->media()->first();
            if ($cover !== null) {
                $cover->forceFill(['is_cover' => true])->save();
                $listing->forceFill(['image_hash' => $cover->phash])->save();
            }
            $item->forceFill(['published_listing_id' => $listing->id])->save();

            return Api::created(['listing_id' => $listing->id, 'status' => $listing->status->value]);
        });
    }

    /** @return array<string, mixed> */
    private function present(InventoryItem $item): array
    {
        $number = fn ($v) => $v === null ? null : (float) $v;

        return [
            'id' => $item->id, 'purpose' => $item->purpose->value, 'property_type' => $item->property_type->value, 'title' => $item->title,
            'city' => $item->city, 'neighborhood' => $item->neighborhood, 'price' => $number($item->price), 'area_land' => $number($item->area_land),
            'area_built' => $number($item->area_built), 'bedrooms' => $item->bedrooms, 'bathrooms' => $item->bathrooms, 'description' => $item->description,
            'cover_url' => $item->relationLoaded('media') ? $item->media->firstWhere('is_cover', true)?->url() : null,
            'published_listing_id' => $item->published_listing_id, 'updated_at' => $item->updated_at?->toIso8601String(),
        ];
    }
}
