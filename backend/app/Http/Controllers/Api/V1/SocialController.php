<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\CustomerRole;
use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\AdvertiserResource;
use App\Models\Customer;
use App\Models\Follow;
use App\Models\Rating;
use App\Models\UserBlock;
use App\Notifications\Notices;
use App\Support\Api;
use App\Support\Blocks;
use App\Support\Sandbox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Following, stars, blocking — between accounts; the blocked side is never told. A tester's follows and stars reach testers only (Sandbox). */
class SocialController extends Controller
{
    public function followers(Request $request): JsonResponse
    {
        $me = $request->user('api');

        return Api::ok(AdvertiserResource::collection($me->followers()->whereNull('anonymized_at')->whereNotIn('customers.id', Sandbox::hiddenFrom($me))->latest('follows.created_at')->limit(500)->get()));
    }

    public function following(Request $request): JsonResponse
    {
        $me = $request->user('api');

        return Api::ok(AdvertiserResource::collection($me->following()->whereNull('anonymized_at')->whereNotIn('customers.id', Sandbox::hiddenFrom($me))->latest('follows.created_at')->limit(500)->get()));
    }

    public function follow(Request $request, Customer $customer): JsonResponse
    {
        $me = $this->other($request, $customer);
        Follow::query()->firstOrCreate(['follower_id' => $me->id, 'followed_id' => $customer->id]);

        return Api::ok(['following' => true]);
    }

    public function unfollow(Request $request, Customer $customer): JsonResponse
    {
        Follow::query()->where('follower_id', $request->user('api')->id)->where('followed_id', $customer->id)->delete();

        return Api::ok(['following' => false]);
    }

    /** One rating per pair, editable; only advertisers are rated. */
    public function rate(Request $request, Customer $customer): JsonResponse
    {
        $me = $this->other($request, $customer);
        if (! $customer->role->canPublish()) {
            throw ApiException::of(ErrorCode::NotFound);
        }
        $data = $request->validate(['stars' => ['required', 'integer', 'between:1,5'], 'comment' => ['nullable', 'string', 'max:500']]);
        $rating = Rating::query()->updateOrCreate(['rater_id' => $me->id, 'rated_id' => $customer->id], $data);
        if ($rating->wasRecentlyCreated && ! Sandbox::hides($customer, $me)) {
            $customer->notify(Notices::rated($customer, (int) $data['stars']));
        }

        return Api::ok(['stars' => $rating->stars]);
    }

    public function block(Request $request, Customer $customer): JsonResponse
    {
        $me = $request->user('api');
        if ($me->id === $customer->id) {
            throw ApiException::of(ErrorCode::InvalidTransition);
        }
        UserBlock::query()->firstOrCreate(['blocker_id' => $me->id, 'blocked_id' => $customer->id]);
        Follow::query()->where(fn ($q) => $q->where('follower_id', $me->id)->where('followed_id', $customer->id))
            ->orWhere(fn ($q) => $q->where('follower_id', $customer->id)->where('followed_id', $me->id))->delete();
        Blocks::forget($me, $customer);

        return Api::ok(['blocked' => true]);
    }

    public function unblock(Request $request, Customer $customer): JsonResponse
    {
        UserBlock::query()->where('blocker_id', $request->user('api')->id)->where('blocked_id', $customer->id)->delete();
        Blocks::forget($request->user('api'), $customer);

        return Api::ok(['blocked' => false]);
    }

    /** Someone else, visible to me, and not a hidden seeker. */
    private function other(Request $request, Customer $customer): Customer
    {
        $me = $request->user('api');
        if ($me->id === $customer->id || $customer->role === CustomerRole::Seeker || $customer->anonymized_at !== null || Blocks::hides($me, $customer)) {
            throw ApiException::of(ErrorCode::NotFound);
        }

        return $me;
    }
}
