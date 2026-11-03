<?php

namespace App\Integrations\Push;

use App\Integrations\Google\ServiceAccount;
use App\Integrations\IntegrationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Firebase Cloud Messaging HTTP v1. Signs in with the service account (ServiceAccount) and sends one message per
 * token.
 */
class FcmPushGateway implements PushGateway
{
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    private readonly ServiceAccount $account;

    private readonly string $project;

    public function __construct(string $credentialsPath)
    {
        $this->account = new ServiceAccount($credentialsPath, 'push');
        $this->project = (string) ($this->account->account['project_id'] ?? '');
        if ($this->project === '') {
            throw new IntegrationException('push', 'unreadable service account file');
        }
    }

    public function name(): string
    {
        return 'fcm';
    }

    public function send(array $tokens, string $title, string $body, array $data = []): array
    {
        $url = "https://fcm.googleapis.com/v1/projects/{$this->project}/messages:send";
        $data = array_map(fn ($value) => (string) $value, $data);
        $dead = [];

        foreach ($tokens as $token) {
            try {
                $response = Http::withToken($this->account->accessToken(self::SCOPE))->acceptJson()->timeout(10)->post($url, ['message' => [
                    'token' => $token,
                    'notification' => ['title' => $title, 'body' => $body],
                    'data' => $data,
                    'android' => ['priority' => 'high'],
                    'apns' => ['payload' => ['aps' => ['sound' => 'default']]],
                ]]);
            } catch (ConnectionException $e) {
                throw new IntegrationException('push', 'connection failed', $e);
            }

            $codes = array_column((array) $response->json('error.details', []), 'errorCode');
            if (in_array('UNREGISTERED', $codes, true)) {
                $dead[] = $token;
            } elseif (! $response->successful()) {
                throw new IntegrationException('push', "HTTP {$response->status()}: ".(string) $response->json('error.status'));
            }
        }

        return $dead;
    }
}
