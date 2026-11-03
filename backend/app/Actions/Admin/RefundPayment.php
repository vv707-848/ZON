<?php

namespace App\Actions\Admin;

use App\Actions\Billing\EndPurchase;
use App\Enums\PaymentStatus;
use App\Filament\Support\Audit;
use App\Integrations\Payments\PaymentGateway;
use App\Integrations\Stores\Stores;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * «استرداد» from the panel: the provider refunds first; then what the payment bought stops (EndPurchase). An
 * advertising licence already issued by REGA cannot be taken back — the note says so. A store purchase is refunded
 * by the store itself (Apple on the customer's request, Google from Play Console); its notice ends it here.
 */
final class RefundPayment
{
    public function __construct(private readonly PaymentGateway $gateway, private readonly EndPurchase $end) {}

    public function __invoke(Payment $payment, string $reason): void
    {
        DB::transaction(function () use ($payment, $reason) {
            // Locked first: a second click (or a second staff member) waits here, then finds «refunded».
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            if (! in_array($payment->status, [PaymentStatus::Paid, PaymentStatus::Unapplied], true)) {
                throw new InvalidArgumentException('Only a paid payment can be refunded.');
            }
            if (in_array($payment->provider, Stores::NAMES, true)) {
                throw new InvalidArgumentException('A store purchase is refunded by the store.');
            }
            if ($payment->provider_ref !== null) {
                $this->gateway->refund($payment->provider_ref, $payment->amount_halalas);   // a provider error rolls the whole refund back
            }
            $payment->forceFill(['status' => PaymentStatus::Refunded])->save();
            ($this->end)($payment);
            Audit::log('payment_refunded', 'استرداد دفعة', $payment, ['reason' => $reason, 'amount' => $payment->amount_halalas]);
        });
    }
}
