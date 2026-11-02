<?php

namespace App\Integrations\Payments;

use App\Models\Payment;
use Illuminate\Http\Request;

/**
 * SIMULATION — no money moves, and the app is told so (`simulation: true`). It replaces the app's
 * PaymentSimulator: the app confirms with reference "fake_{public_id}" (paid, exact amount) or
 * "fake_{public_id}_fail" (declined). Refused in production unless PRODUCTION_ALLOWS_FAKE names "payments".
 */
class FakePaymentGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'fake';
    }

    public function clientPayload(Payment $payment): array
    {
        return [
            'simulation' => true,
            'reference' => "fake_{$payment->public_id}",
            'decline_reference' => "fake_{$payment->public_id}_fail",
        ];
    }

    public function fetch(string $reference): GatewayPayment
    {
        if (preg_match('/^fake_([0-9A-Za-z]{26})(_fail)?$/', $reference, $m) !== 1) {
            return new GatewayPayment($reference, GatewayPayment::FAILED, 0, 'SAR', null, 'unknown reference');
        }
        $payment = Payment::query()->where('public_id', $m[1])->first();
        if ($payment === null) {
            return new GatewayPayment($reference, GatewayPayment::FAILED, 0, 'SAR', null, 'unknown payment');
        }
        $declined = ($m[2] ?? '') !== '';

        return new GatewayPayment(
            $reference,
            $declined ? GatewayPayment::FAILED : GatewayPayment::PAID,
            $payment->amount_halalas,
            $payment->currency,
            $payment->public_id,
            $declined ? 'declined (simulation)' : null,
        );
    }

    public function refund(string $reference, int $amountHalalas): void {}

    public function webhookReference(Request $request): ?string
    {
        return null;   // the simulation has no webhooks
    }
}
