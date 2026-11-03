<?php

namespace App\Http\Resources;

use App\Models\Payment;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A payment as its buyer sees it — from the website's checkout (Moyasar) or a store purchase in the apps.
 *
 * @mixin Payment
 */
class PaymentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'payment_id' => $this->public_id,
            'purpose' => $this->purpose->value,
            'status' => $this->status->value,
            'amount' => $this->amount()->riyals(),
            'vat' => (new Money($this->vat_halalas))->riyals(),
            'currency' => $this->currency,
            'provider' => $this->provider,
            'failure_reason' => $this->failure_reason,
            'paid_at' => $this->paid_at?->toIso8601String(),
        ];
    }
}
