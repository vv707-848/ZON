<?php

namespace App\Actions\Billing;

use App\Enums\PaymentStatus;
use App\Integrations\Payments\GatewayPayment;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * A refund made at the provider itself (Moyasar's dashboard), as the provider reports it when read back: the whole
 * amount of the very charge that paid this payment ends what it bought (EndPurchase), once — the provider sends its
 * notice up to six times, and a refund from our panel arrives here already «refunded». A partial refund is a
 * gesture, not an end: the purchase stays, and the log says so.
 */
final class RecordProviderRefund
{
    public function __construct(private readonly EndPurchase $end) {}

    public function __invoke(Payment $payment, GatewayPayment $remote): void
    {
        if ($remote->reference !== $payment->provider_ref || $remote->paymentPublicId !== $payment->public_id || $remote->amountHalalas !== $payment->amount_halalas) {
            return;                                                    // not the charge that paid it
        }
        if (! $remote->fullyRefunded()) {
            Log::info('payment.partly_refunded', ['payment' => $payment->public_id, 'refunded' => $remote->refundedHalalas]);

            return;
        }
        DB::transaction(function () use ($payment) {
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            if (! in_array($payment->status, [PaymentStatus::Paid, PaymentStatus::Unapplied], true)) {
                return;                                                // refunded already, from the panel or a notice before
            }
            $payment->forceFill(['status' => PaymentStatus::Refunded])->save();
            ($this->end)($payment);
            activity('payments')->performedOn($payment)->event('provider_refund')
                ->withProperties(['provider' => $payment->provider, 'amount' => $payment->amount_halalas])->log('استرداد من مزوّد الدفع');
        });
    }
}
