<?php

namespace App\Actions\Admin;

use App\Enums\PaymentStatus;
use App\Filament\Support\Audit;
use App\Integrations\Payments\PaymentGateway;
use App\Models\BoostUsage;
use App\Models\Listing;
use App\Models\Payment;
use App\Models\SearchRequest;
use App\Models\Subscription;
use App\Services\Billing\Entitlements;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * «استرداد» from the panel: the provider refunds first; then what the payment bought stops (its subscription
 * period, its boost). An advertising licence already issued by REGA cannot be taken back — the note says so.
 */
final class RefundPayment
{
    public function __construct(private readonly PaymentGateway $gateway) {}

    public function __invoke(Payment $payment, string $reason): void
    {
        DB::transaction(function () use ($payment, $reason) {
            // Locked first: a second click (or a second staff member) waits here, then finds «refunded».
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            if (! in_array($payment->status, [PaymentStatus::Paid, PaymentStatus::Unapplied], true)) {
                throw new InvalidArgumentException('Only a paid payment can be refunded.');
            }
            if ($payment->provider_ref !== null) {
                $this->gateway->refund($payment->provider_ref, $payment->amount_halalas);   // a provider error rolls the whole refund back
            }
            $payment->forceFill(['status' => PaymentStatus::Refunded])->save();
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
            Audit::log('payment_refunded', 'استرداد دفعة', $payment, ['reason' => $reason, 'amount' => $payment->amount_halalas]);
        });
    }
}
