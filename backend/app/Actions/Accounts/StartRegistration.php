<?php

namespace App\Actions\Accounts;

use App\Enums\CustomerRole;
use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Models\Customer;
use App\Support\Fingerprint;
use App\Support\Otp;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

/**
 * Step 1 of «حساب جديد»: the form is checked and kept aside (cache, hashed password) while the phone gets its
 * code. No row exists in customers until the code is verified, so nobody can hold another person's number.
 */
final class StartRegistration
{
    /** Accounts one device may open in 24 hours. */
    private const PER_DEVICE_DAY = 3;

    public function __construct(private readonly Otp $otp) {}

    /** @param array{role: string, name: string, phone: string, city: string, username: string, password: string, fal_number?: ?string} $form */
    public function __invoke(array $form, ?string $deviceId, ?string $ip): void
    {
        $device = Fingerprint::of($deviceId);
        if ($device !== null && Customer::query()->where('signup_device_hash', $device)->where('created_at', '>', now()->subDay())->count() >= self::PER_DEVICE_DAY) {
            throw ApiException::of(ErrorCode::TooManyRequests);
        }

        Cache::put(self::key($form['phone']), [
            'role' => CustomerRole::from($form['role'])->value,
            'name' => trim($form['name']),
            'phone' => $form['phone'],
            'city' => $form['city'],
            'username' => trim($form['username']),
            'password' => Hash::make($form['password']),
            'fal_number' => $form['fal_number'] ?? null,
            'signup_device_hash' => $device,
            'signup_ip_hash' => Fingerprint::of($ip),
        ], now()->addMinutes((int) config('zon.registration_ttl_minutes')));

        $this->otp->send($form['phone'], Otp::REGISTER);
    }

    public static function key(string $phone): string
    {
        return "registration:{$phone}";
    }
}
