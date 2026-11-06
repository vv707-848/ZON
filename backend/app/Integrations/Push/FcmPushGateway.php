<?php

namespace App\Integrations\Push;

use App\Integrations\Google\ServiceAccount;
use App\Integrations\IntegrationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Firebase Cloud Messaging HTTP v1: one message per phone, signed in with the service account (ServiceAccount).
 *
 * A phone is addressed by its Firebase Installation ID (`message.fid`): the app registers with FCM's FID API
 * (firebase_messaging 17), registration tokens are deprecated (`message.token`: «Deprecated: Use fid instead»).
 * The ids are ours (customer_devices.push_token, one per signed-in phone) — no FCM device groups, which stop
 * working after 29 September 2027 (firebase.google.com/docs/cloud-messaging/migrate-off-device-groups).
 *
 * An id FCM says will never work again is returned as dead, and the next ones are still sent
 * (firebase.google.com/docs/cloud-messaging/manage-tokens): UNREGISTERED (404), INVALID_ARGUMENT on the id itself
 * (400, a field violation on message.fid or message.token — never because of our payload), SENDER_ID_MISMATCH
 * (403, an id of another Firebase project). Anything else concerns every phone — our sign-in, our payload, FCM
 * itself — so the batch stops with the error; the bell has the notice anyway.
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

    public function send(array $tokens, string $title, string $body, array $data = []): array
    {
        $url = "https://fcm.googleapis.com/v1/projects/{$this->project}/messages:send";
        $data = array_map(fn ($value) => (string) $value, $data);
        $dead = [];

        foreach ($tokens as $token) {
            try {
                $response = Http::withToken($this->account->accessToken(self::SCOPE))->acceptJson()->timeout(10)->post($url, ['message' => [
                    'fid' => $token,
                    'notification' => ['title' => $title, 'body' => $body],
                    'data' => $data,
                    'android' => ['priority' => 'high'],
                    'apns' => ['payload' => ['aps' => ['sound' => 'default']]],
                ]]);
            } catch (ConnectionException $e) {
                throw new IntegrationException('push', 'connection failed', $e);
            }

            if ($response->successful()) {
                continue;
            }
            if (self::tokenIsDead($response)) {
                $dead[] = $token;

                continue;
            }

            throw new IntegrationException('push', "HTTP {$response->status()}: ".(string) $response->json('error.status'));
        }

        return $dead;
    }

    /** Whether FCM refused this message because of its id, for good. */
    public static function tokenIsDead(Response $response): bool
    {
        $details = (array) $response->json('error.details', []);
        $codes = array_column($details, 'errorCode');
        if (in_array('UNREGISTERED', $codes, true) || in_array('SENDER_ID_MISMATCH', $codes, true)) {
            return true;
        }
        if ($response->status() !== 400 || ! in_array('INVALID_ARGUMENT', $codes, true)) {
            return false;
        }
        $violations = array_merge(...array_map(fn ($detail) => is_array($detail) ? (array) ($detail['fieldViolations'] ?? []) : [], $details));

        return array_intersect(['message.fid', 'message.token'], array_column($violations, 'field')) !== [];
    }
}
