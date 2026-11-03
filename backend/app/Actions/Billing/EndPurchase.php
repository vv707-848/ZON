<?php

namespace App\Actions\Billing;

use App\Models\BoostUsage;
use App\Models\Listing;
use App\Models\Payment;
use App\Models\SearchRequest;
use App\Models\Subscription;
use App\Services\Billing\Entitlements;

/** What a refunded payment bought stops now: its plan period and its boost. A staff refund and a store's refund both end here. */
final class EndPurchase
{
    public function __invoke(Payment $payment): void
    {
        Subscription::query()->where('payment_id', $payment->id)->whereNull('cancelled_at')->update(['cancelled_at' => now()]);
        foreach (BoostUsage::query()->with('target')->where('payment_id', $payment->id)->get() as $boost) {
            $boost->forceFill(['active_until' => now(), 'ended_notified_at' => now()])->save();
            $target = $boost->target;
            if ($target instanceof Listing) {
                $target->forceFill([$boost->kind->value === 'featured' ? 'featured_until' : 'urgent_until' => now()])->save();
            } elseif ($target instanceof SearchRequest) {
                $target->forceFill(['urgent' => false])->save();
            }
        }
        Entitlements::forget($payment->customer);
    }
}
