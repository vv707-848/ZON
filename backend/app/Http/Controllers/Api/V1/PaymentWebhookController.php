<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Billing\ConfirmPayment;
use App\Actions\Billing\RecordProviderRefund;
use App\Http\Controllers\Controller;
use App\Integrations\Payments\GatewayPayment;
use App\Integrations\Payments\PaymentGateway;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The provider's server-to-server notice: a payment the app closed before confirming, or a refund made at the
 * provider. The notice is only a hint: the payment is read back from the provider, exactly as the app's confirm does.
 */
class PaymentWebhookController extends Controller
{
    public function handle(Request $request, string $provider, PaymentGateway $gateway, ConfirmPayment $confirm, RecordProviderRefund $refund): JsonResponse
    {
        $reference = $provider === $gateway->name() ? $gateway->webhookReference($request) : null;
        if ($reference === null) {
            return new JsonResponse(['received' => false], 403);
        }
        $remote = $gateway->fetch($reference);
        $payment = $remote->paymentPublicId === null ? null : Payment::query()->where('public_id', $remote->paymentPublicId)->first();
        if ($payment !== null) {
            $remote->status === GatewayPayment::REFUNDED ? $refund($payment, $remote) : $confirm($payment, $reference);
        }

        return new JsonResponse(['received' => true]);
    }
}
