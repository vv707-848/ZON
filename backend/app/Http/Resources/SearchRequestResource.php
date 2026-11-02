<?php

namespace App\Http\Resources;

use App\Enums\AreaRequestsAccess;
use App\Enums\CustomerRole;
use App\Models\Customer;
use App\Models\SearchRequest;
use App\Services\Billing\Entitlements;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A request on the board (ask_card.dart). Who posted it is hidden from guests and from brokers without the
 * area-requests service (the app's «فعّل الخدمة لفتح التواصل»); everybody sees the author's role.
 *
 * @mixin SearchRequest
 */
class SearchRequestResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $viewer = $request->user('api');
        $number = fn ($v) => $v === null ? null : (float) $v;
        $access = self::access($viewer instanceof Customer ? $viewer : null);
        $mine = $viewer instanceof Customer && $viewer->id === $this->customer_id;

        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'purpose' => $this->purpose->value,
            'city' => $this->city,
            'neighborhoods' => $this->neighborhoods,
            'property_types' => $this->property_types,
            'budget_min' => $number($this->budget_min),
            'budget_max' => $number($this->budget_max),
            'area_min' => $number($this->area_min),
            'area_max' => $number($this->area_max),
            'rooms_min' => $this->rooms_min,
            'rooms_max' => $this->rooms_max,
            'description' => $this->description,
            'urgent' => $this->urgent,
            'created_at' => $this->created_at?->toIso8601String(),
            'author_role' => $this->customer->role->value,
            'author' => $mine || $access !== AreaRequestsAccess::None ? new AdvertiserResource($this->customer) : null,
            'can_add_to_clients' => ! $mine && $access === AreaRequestsAccess::Full,
        ];
    }

    /** What a viewer may see of who posted requests. */
    public static function access(?Customer $viewer): AreaRequestsAccess
    {
        return match (true) {
            $viewer === null => AreaRequestsAccess::None,
            $viewer->role === CustomerRole::Broker => Entitlements::for($viewer)->areaRequests(),
            $viewer->role === CustomerRole::Developer => AreaRequestsAccess::Full,
            default => AreaRequestsAccess::Preview,
        };
    }
}
