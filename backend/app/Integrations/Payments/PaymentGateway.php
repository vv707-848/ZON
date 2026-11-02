<?php

namespace App\Integrations\Payments;

use App\Models\Payment;
use Illuminate\Http\Request;

/**
 * The payment provider. The app pays with the provider's own SDK using clientPayload(); the backend then reads
 * the payment back with fetch() and applies it only if the amount, currency and our payment id all match.
 */
interface PaymentGateway
{
    public function name(): string;

    /** @return array<string, mixed> what the app's payment sheet needs (never a secret) */
    public function clientPayload(Payment $payment): array;

    public function fetch(string $reference): GatewayPayment;

    public function refund(string $reference, int $amountHalalas): void;

    /** The provider's payment reference of a genuine webhook call, or null when it cannot be trusted. */
    public function webhookReference(Request $request): ?string;
}
