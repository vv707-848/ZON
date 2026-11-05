<?php

namespace App\Actions\Accounts;

use App\Models\Customer;
use App\Models\CustomerDevice;

/**
 * The Sanctum token of one phone — one session per phone, named after it — and that phone's push token when the app
 * sends it, tied to the session: ending the session ends the pushes (customer_devices.access_token_id cascades).
 */
final class IssueToken
{
    /** @param array{device_name?: ?string, push_token?: ?string, platform?: ?string, app_version?: ?string} $device */
    public function __invoke(Customer $customer, array $device): string
    {
        $token = $customer->createToken(mb_substr((string) ($device['device_name'] ?? 'app'), 0, 60));
        if (! empty($device['push_token'])) {
            self::registerDevice($customer, $device, $token->accessToken->getKey());
        }
        $customer->forceFill(['last_seen_at' => now()])->save();

        return $token->plainTextToken;
    }

    /** @param array{push_token?: ?string, platform?: ?string, app_version?: ?string} $device */
    public static function registerDevice(Customer $customer, array $device, int|string|null $session): void
    {
        // A token moves with the phone: re-registered under another account, it leaves the previous one.
        CustomerDevice::query()->updateOrCreate(['push_token' => $device['push_token']], [
            'customer_id' => $customer->id,
            'access_token_id' => $session,
            'platform' => in_array($device['platform'] ?? null, ['ios', 'android'], true) ? $device['platform'] : 'android',
            'app_version' => $device['app_version'] ?? null,
            'last_seen_at' => now(),
        ]);
    }
}
