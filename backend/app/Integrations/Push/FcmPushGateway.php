<?php

namespace App\Integrations\Push;

use App\Integrations\IntegrationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Firebase Cloud Messaging HTTP v1. Signs in with the service account (a JWT signed with its private key,
 * exchanged for an access token cached until shortly before it expires) and sends one message per token.
 */
class FcmPushGateway implements PushGateway
{
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    /** @var array{project_id: string, client_email: string, private_key: string} */
    private array $account;

    public function __construct(string $credentialsPath)
    {
        $account = is_file($credentialsPath) ? json_decode((string) file_get_contents($credentialsPath), true) : null;
        if (! is_array($account) || ! isset($account['project_id'], $account['client_email'], $account['private_key'])) {
            throw new IntegrationException('push', 'unreadable service account file');
        }
        $this->account = $account;
    }

    public function name(): string
    {
        return 'fcm';
    }

    public function send(array $tokens, string $title, string $body, array $data = []): array
    {
        $url = "https://fcm.googleapis.com/v1/projects/{$this->account['project_id']}/messages:send";
        $data = array_map(fn ($value) => (string) $value, $data);
        $dead = [];

        foreach ($tokens as $token) {
            try {
                $response = Http::withToken($this->accessToken())->acceptJson()->timeout(10)->post($url, ['message' => [
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

    private function accessToken(): string
    {
        $key = 'fcm.token.'.md5($this->account['client_email']);
        if (is_string($cached = Cache::get($key))) {
            return $cached;
        }

        $now = time();
        $jwt = $this->jwt(['iss' => $this->account['client_email'], 'scope' => self::SCOPE, 'aud' => self::TOKEN_URL, 'iat' => $now, 'exp' => $now + 3600]);
        $response = Http::asForm()->timeout(10)->post(self::TOKEN_URL, ['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt]);
        $token = $response->json('access_token');
        if (! $response->successful() || ! is_string($token)) {
            throw new IntegrationException('push', 'service account sign-in refused');
        }
        Cache::put($key, $token, max(60, (int) $response->json('expires_in', 3600) - 120));

        return $token;
    }

    /** @param array<string, int|string> $claims */
    private function jwt(array $claims): string
    {
        $encode = fn (array $part) => rtrim(strtr(base64_encode((string) json_encode($part)), '+/', '-_'), '=');
        $input = $encode(['alg' => 'RS256', 'typ' => 'JWT']).'.'.$encode($claims);
        if (! openssl_sign($input, $signature, $this->account['private_key'], OPENSSL_ALGO_SHA256)) {
            throw new IntegrationException('push', 'cannot sign with the service account key');
        }

        return $input.'.'.rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
    }
}
