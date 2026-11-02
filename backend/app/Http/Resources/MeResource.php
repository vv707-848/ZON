<?php

namespace App\Http\Resources;

use App\Models\Customer;
use App\Services\Billing\Entitlements;
use App\Support\Phone;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The signed-in account as the app's Session needs it, with its licences, plan and switches.
 *
 * @mixin Customer
 */
class MeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Customer $me */
        $me = $this->resource;
        $plan = Entitlements::for($me);

        return [
            'id' => $me->id,
            'role' => $me->role->value,
            'status' => $me->status->value,
            'name' => $me->name,
            'username' => $me->username,
            'phone' => Phone::local($me->phone),
            'city' => $me->city,
            'fal' => $me->fal_number === null ? null : [
                'number' => $me->fal_number,
                'status' => $me->fal_status->value,
                'expires_at' => $me->fal_expires_at?->toDateString(),
            ],
            'nafath_status' => $me->nafath_status->value,
            'is_tester' => $me->is_tester,
            'notification_prefs' => $me->noticePrefs(),
            'plan' => [
                'code' => $plan->package()->code,
                'name' => $plan->package()->name,
                'subscribed' => $plan->isSubscribed(),
                'ends_at' => $plan->subscription()?->ends_at?->toIso8601String(),
            ],
            'has_preference' => $me->preferences()->where('is_active', true)->exists(),
            'created_at' => $me->created_at?->toIso8601String(),
        ];
    }
}
