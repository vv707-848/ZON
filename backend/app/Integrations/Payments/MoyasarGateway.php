<?php

namespace App\Integrations\Payments;

use App\Integrations\IntegrationException;
use App\Models\Payment;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Moyasar (api.moyasar.com/v1): Basic auth with the secret key; amounts in halalas; our payment id travels in
 * metadata.payment_id. Webhooks carry the shared secret_token, compared in constant time.
 */
class MoyasarGateway implements PaymentGateway
{
    public function __construct(
        private readonly string $secretKey,
        private readonly string $publishableKey,
        private readonly string $webhookSecret,
        private readonly string $url = 'https://api.moyasar.com/v1',
    ) {}

    public function name(): string
    {
        return 'moyasar';
    }

    public function clientPayload(Payment $payment): array
    {
        return [
            'publishable_key' => $this->publishableKey,
            'amount' => $payment->amount_halalas,
            'currency' => $payment->currency,
            'description' => "Zon {$payment->purpose->value} {$payment->public_id}",
            'metadata' => ['payment_id' => $payment->public_id],
        ];
    }

    public function fetch(string $reference): GatewayPayment
    {
        $data = $this->call(fn (PendingRequest $http) => $http->get("{$this->url}/payments/".rawurlencode($reference)))->json();

        return new GatewayPayment(
            (string) $data['id'],
            match ($data['status'] ?? null) {
                'paid', 'captured' => GatewayPayment::PAID,
                'failed', 'voided' => GatewayPayment::FAILED,
                'refunded' => GatewayPayment::REFUNDED,
                default => GatewayPayment::PENDING,
            },
            (int) ($data['amount'] ?? 0),
            strtoupper((string) ($data['currency'] ?? '')),
            isset($data['metadata']['payment_id']) ? (string) $data['metadata']['payment_id'] : null,
            isset($data['source']['message']) ? (string) $data['source']['message'] : null,
        );
    }

    public function refund(string $reference, int $amountHalalas): void
    {
        $this->call(fn (PendingRequest $http) => $http->post("{$this->url}/payments/".rawurlencode($reference).'/refund', ['amount' => $amountHalalas]));
    }

    public function webhookReference(Request $request): ?string
    {
        $token = (string) $request->input('secret_token');
        $reference = $request->input('data.id');

        return $this->webhookSecret !== '' && hash_equals($this->webhookSecret, $token) && is_string($reference) ? $reference : null;
    }

    /** @param callable(PendingRequest): Response $send */
    private function call(callable $send): Response
    {
        try {
            $response = $send(Http::withBasicAuth($this->secretKey, '')->acceptJson()->timeout(15));
        } catch (ConnectionException $e) {
            throw new IntegrationException('payments', 'connection failed', $e);
        }
        if (! $response->successful()) {
            throw new IntegrationException('payments', "HTTP {$response->status()}: ".(string) $response->json('message'));
        }

        return $response;
    }
}
