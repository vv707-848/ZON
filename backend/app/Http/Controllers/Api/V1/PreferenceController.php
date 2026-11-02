<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\PreferenceRequest;
use App\Http\Resources\PreferenceResource;
use App\Models\BuyerPreference;
use App\Models\Customer;
use App\Services\Billing\Entitlements;
use App\Support\Api;
use App\Support\Ownership;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The seeker's wishes (one when free, more with a plan); the matching and the twice-daily notices read them. */
class PreferenceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return Api::ok(PreferenceResource::collection($request->user('api')->preferences()->latest()->get()));
    }

    public function store(PreferenceRequest $request): JsonResponse
    {
        $me = $request->customer();
        $this->assertRoomForAnActiveWish($me);
        $preference = $me->preferences()->create($request->validated() + ['property_types' => []]);

        return Api::created(new PreferenceResource($preference));
    }

    public function update(PreferenceRequest $request, BuyerPreference $preference): JsonResponse
    {
        Ownership::assert($preference, $request->customer());
        if (! $preference->is_active && $request->has('is_active') && $request->boolean('is_active')) {
            $this->assertRoomForAnActiveWish($request->customer());   // switching one back on counts like adding one
        }
        $preference->fill($request->validated())->save();

        return Api::ok(new PreferenceResource($preference));
    }

    /** The plan's number of active wishes (Package::preferences_limit). */
    private function assertRoomForAnActiveWish(Customer $me): void
    {
        if ($me->preferences()->where('is_active', true)->count() >= Entitlements::for($me)->preferencesLimit()) {
            throw ApiException::of(ErrorCode::PlanLimitReached);
        }
    }

    public function destroy(Request $request, BuyerPreference $preference): JsonResponse
    {
        Ownership::assert($preference, $request->user('api'));
        $preference->delete();

        return Api::ok();
    }
}
