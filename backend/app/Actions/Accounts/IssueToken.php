<?php

namespace App\Actions\Accounts;

use App\Models\Customer;
use App\Models\CustomerDevice;

/** The Sanctum token of one phone, and that phone's push token when the app sends it. */
final class IssueToken
{
    /** @param array{device_name?: ?string, push_token?: ?string, platform?: ?string, app_version?: ?string} $device */
    public function __invoke(Customer $customer, array $device): string
    {
        if (! empty($device['push_token'])) {
            self::registerDevice($customer, $device);
        }
        $customer->forceFill(['last_seen_at' => now()])->save();

        return $customer->createToken(mb_substr((string) ($device['device_name'] ?? 'app'), 0, 60))->plainTextToken;
    }

    /** @param array{push_token?: ?string, platform?: ?string, app_version?: ?string} $device */
    public static function registerDevice(Customer $customer, array $device): void
    {
        // A token moves with the phone: re-registered under another account, it leaves the previous one.
        CustomerDevice::query()->updateOrCreate(['push_token' => $device['push_token']], [
            'customer_id' => $customer->id,
            'platform' => in_array($device['platform'] ?? null, ['ios', 'android'], true) ? $device['platform'] : 'android',
            'app_version' => $device['app_version'] ?? null,
            'last_seen_at' => now(),
        ]);
    }
}
