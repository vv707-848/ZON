<?php

namespace App\Integrations\Sms;

use App\Integrations\IntegrationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/** Taqnyat (api.taqnyat.sa): Bearer token; the number is sent without "+" (966…). */
class TaqnyatSmsGateway implements SmsGateway
{
    public function __construct(
        private readonly string $token,
        private readonly string $sender,
        private readonly string $url = 'https://api.taqnyat.sa',
    ) {}

    public function send(string $phone, string $text): void
    {
        try {
            $response = Http::withToken($this->token)->acceptJson()->timeout(10)
                ->post(rtrim($this->url, '/').'/v1/messages', [
                    'recipients' => [ltrim($phone, '+')],
                    'body' => $text,
                    'sender' => $this->sender,
                ]);
        } catch (ConnectionException $e) {
            throw new IntegrationException('sms', 'connection failed', $e);
        }

        if (! $response->successful()) {
            throw new IntegrationException('sms', "HTTP {$response->status()}: ".(string) $response->json('message'));
        }
    }
}
