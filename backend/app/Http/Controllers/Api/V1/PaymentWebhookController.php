<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Billing\ConfirmPayment;
use App\Http\Controllers\Controller;
use App\Integrations\Payments\PaymentGateway;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The provider's server-to-server notice (in case the app closed before confirming). The notice is only a hint:
 * the payment is read back from the provider, exactly as the app's confirm does.
 */
class PaymentWebhookController extends Controller
{
    public function handle(Request $request, string $provider, PaymentGateway $gateway, ConfirmPayment $confirm): JsonResponse
    {
        $reference = $provider === $gateway->name() ? $gateway->webhookReference($request) : null;
        if ($reference === null) {
            return new JsonResponse(['received' => false], 403);
        }
        $publicId = $gateway->fetch($reference)->paymentPublicId;
        $payment = $publicId === null ? null : Payment::query()->where('public_id', $publicId)->first();
        if ($payment !== null) {
            $confirm($payment, $reference);
        }

        return new JsonResponse(['received' => true]);
    }
}
