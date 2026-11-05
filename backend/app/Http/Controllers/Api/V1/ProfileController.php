<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\AdvertiserResource;
use App\Http\Resources\ListingResource;
use App\Models\Customer;
use App\Models\Follow;
use App\Models\Rating;
use App\Support\Api;
use App\Support\PublicView;
use App\Support\Sandbox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The public profile (/users/:name): advertisers only — a seeker's profile does not exist publicly. */
class ProfileController extends Controller
{
    public function show(Request $request, Customer $customer): JsonResponse
    {
        $viewer = $this->visible($request, $customer);
        $sandbox = Sandbox::hiddenFrom($viewer);                 // a tester's stars and follows count for testers only
        $ratings = Rating::query()->visible()->where('rated_id', $customer->id)->whereNotIn('rater_id', $sandbox);

        return Api::ok((new AdvertiserResource($customer))->resolve($request) + [
            'city' => $customer->city,
            'member_since' => $customer->created_at?->toDateString(),
            'nafath_verified' => $customer->nafath_status->value === 'verified',
            'rating' => ['average' => round((float) (clone $ratings)->avg('stars'), 1), 'count' => (clone $ratings)->count()],
            'my_rating' => $viewer ? Rating::query()->where('rater_id', $viewer->id)->where('rated_id', $customer->id)->value('stars') : null,
            'followers_count' => Follow::query()->where('followed_id', $customer->id)->whereNotIn('follower_id', $sandbox)->count(),
            'following_count' => Follow::query()->where('follower_id', $customer->id)->whereNotIn('followed_id', $sandbox)->count(),
            'listings_count' => $customer->listings()->live()->count(),
            'is_following' => $viewer !== null && Follow::query()->where('follower_id', $viewer->id)->where('followed_id', $customer->id)->exists(),
        ]);
    }

    public function listings(Request $request, Customer $customer): JsonResponse
    {
        $this->visible($request, $customer);
        $page = $customer->listings()->live()->with(['customer', 'media' => fn ($q) => $q->where('is_cover', true)])->latest('published_at')->paginate(20);

        return Api::page($page, ListingResource::class);
    }

    private function visible(Request $request, Customer $customer): ?Customer
    {
        $viewer = $request->user('api');
        $viewer = $viewer instanceof Customer ? $viewer : null;
        if (! PublicView::profile($customer, $viewer)) {
            throw ApiException::of(ErrorCode::NotFound);
        }

        return $viewer;
    }
}
