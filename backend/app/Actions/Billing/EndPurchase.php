<?php

namespace App\Actions\Billing;

use App\Enums\BoostKind;
use App\Indexing\IndexSync;
use App\Models\BoostUsage;
use App\Models\Listing;
use App\Models\Payment;
use App\Models\Project;
use App\Models\SearchRequest;
use App\Models\Subscription;
use App\Services\Billing\Entitlements;

/**
 * What a refunded payment bought stops now: its plan period and its boost — that boost only. A boost it paid for
 * that had already ended leaves its listing or request alone: what runs there now is another purchase (a later
 * payment, the plan's balance, a grant). A staff refund, a provider's refund and a store's refund all end here.
 */
final class EndPurchase
{
    public function __construct(private readonly IndexSync $index) {}

    public function __invoke(Payment $payment): void
    {
        Subscription::query()->where('payment_id', $payment->id)->whereNull('cancelled_at')->update(['cancelled_at' => now()]);
        foreach (BoostUsage::query()->with('target')->where('payment_id', $payment->id)->get() as $boost) {
            $running = $boost->active_until === null || $boost->active_until->isFuture();
            $boost->forceFill(['active_until' => $running ? now() : $boost->active_until, 'ended_notified_at' => $boost->ended_notified_at ?? now()])->save();
            if ($running) {
                $this->endOn($boost);
            }
        }
        Entitlements::forget($payment->customer);
    }

    /** The boost's mark comes off its target — unless another boost of the kind still runs there, which keeps it to its own end. */
    private function endOn(BoostUsage $boost): void
    {
        $other = BoostUsage::query()->where('target_type', $boost->target_type)->where('target_id', $boost->target_id)->where('kind', $boost->kind)
            ->whereKeyNot($boost->id)->where('active_until', '>', now())->max('active_until');
        $target = $boost->target;
        if ($target instanceof Listing) {
            $target->forceFill([$boost->kind === BoostKind::Featured ? 'featured_until' : 'urgent_until' => $other ?? now()])->save();
        } elseif ($target instanceof Project) {
            $target->forceFill(['featured_until' => $other ?? now()])->save();
        } elseif ($target instanceof SearchRequest && $other === null) {
            $target->forceFill(['urgent' => false])->save();
            $this->index->request($target);
        }
    }
}
