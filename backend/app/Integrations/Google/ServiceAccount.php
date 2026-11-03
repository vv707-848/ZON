<?php

namespace App\Integrations\Google;

use App\Integrations\IntegrationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * A Google service account signing in (OAuth 2.0 for server-to-server): a JWT signed with its private key is
 * exchanged for an access token, cached until shortly before it expires. Used by FCM and Google Play.
 */
final class ServiceAccount
{
    public const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    /** @var array{project_id?: string, client_email: string, private_key: string} */
    public readonly array $account;

    /** @param  string  $service  the integration this account serves, named in errors */
    public function __construct(string $credentialsPath, private readonly string $service)
    {
        $account = is_file($credentialsPath) ? json_decode((string) file_get_contents($credentialsPath), true) : null;
        if (! is_array($account) || ! isset($account['client_email'], $account['private_key'])) {
            throw new IntegrationException($service, 'unreadable service account file');
        }
        $this->account = $account;
    }

    public function accessToken(string $scope): string
    {
        $key = 'google.token.'.md5($this->account['client_email'].'|'.$scope);
        if (is_string($cached = Cache::get($key))) {
            return $cached;
        }

        $now = time();
        $jwt = $this->jwt(['iss' => $this->account['client_email'], 'scope' => $scope, 'aud' => self::TOKEN_URL, 'iat' => $now, 'exp' => $now + 3600]);
        try {
            $response = Http::asForm()->timeout(10)->post(self::TOKEN_URL, ['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt]);
        } catch (ConnectionException $e) {
            throw new IntegrationException($this->service, 'connection failed', $e);
        }
        $token = $response->json('access_token');
        if (! $response->successful() || ! is_string($token)) {
            throw new IntegrationException($this->service, 'service account sign-in refused');
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
            throw new IntegrationException($this->service, 'cannot sign with the service account key');
        }

        return $input.'.'.rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
    }
}
