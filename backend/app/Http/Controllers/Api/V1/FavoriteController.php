<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\ListingResource;
use App\Http\Resources\ProjectResource;
use App\Models\Favorite;
use App\Models\Listing;
use App\Models\Project;
use App\Support\Api;
use App\Support\Blocks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** «إعجاب» on listings and projects; the list shows only what is still public. */
class FavoriteController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $me = $request->user('api');
        $ids = fn (string $type) => Favorite::query()->where('customer_id', $me->id)->where('favoritable_type', $type)->latest('id')->pluck('favoritable_id');
        $request->attributes->set('favorite_listing_ids', $ids('listing')->map(fn ($id) => (int) $id)->all());
        $hidden = Blocks::hiddenFor($me);

        return Api::ok([
            'listings' => ListingResource::collection(Listing::query()->live()->whereKey($ids('listing'))->whereNotIn('customer_id', $hidden)
                ->with(['customer', 'media' => fn ($q) => $q->where('is_cover', true)])->get()),
            'projects' => ProjectResource::collection(Project::query()->published()->whereKey($ids('project'))->whereNotIn('developer_id', $hidden)
                ->with(['developer', 'media' => fn ($q) => $q->where('is_cover', true), 'units'])->get()),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['type' => ['required', 'in:listing,project'], 'id' => ['required', 'integer']]);
        $hidden = Blocks::hiddenFor($request->user('api'));
        $model = $data['type'] === 'listing'
            ? Listing::query()->live()->whereNotIn('customer_id', $hidden)->find($data['id'])
            : Project::query()->published()->whereNotIn('developer_id', $hidden)->find($data['id']);
        if ($model === null) {
            throw ApiException::of(ErrorCode::NotFound);
        }
        Favorite::query()->firstOrCreate(['customer_id' => $request->user('api')->id, 'favoritable_type' => $data['type'], 'favoritable_id' => $model->getKey()]);

        return Api::ok(['favorite' => true]);
    }

    public function destroy(Request $request, string $type, int $id): JsonResponse
    {
        Favorite::query()->where('customer_id', $request->user('api')->id)->where('favoritable_type', $type)->where('favoritable_id', $id)->delete();

        return Api::ok(['favorite' => false]);
    }
}
