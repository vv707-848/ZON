<?php

namespace App\Support;

use App\Integrations\Drivers;
use App\Integrations\Sms\SmsGateway;
use Illuminate\Support\Facades\Cache;

/**
 * One-time codes sent by SMS. Only a hash is kept, for config('zon.otp.ttl_minutes'); a code dies after
 * config('zon.otp.max_attempts') wrong tries. The fixed test code (the app's 204815) is accepted only with the
 * simulated SMS driver outside production.
 */
final class Otp
{
    public const REGISTER = 'register';

    public const RESET = 'reset';

    public const PHONE_CHANGE = 'phone_change';

    public function __construct(private readonly SmsGateway $sms) {}

    public function send(string $phone, string $purpose): void
    {
        $code = str_pad((string) random_int(0, 10 ** config('zon.otp.length') - 1), (int) config('zon.otp.length'), '0', STR_PAD_LEFT);
        Cache::put($this->key($phone, $purpose), ['hash' => hash('sha256', $code), 'attempts' => 0], now()->addMinutes((int) config('zon.otp.ttl_minutes')));

        $this->sms->send($phone, "رمز التحقق في زون: {$code}\nلا تشاركه مع أحد.");
    }

    /** True once per sent code: a match consumes it. */
    public function verify(string $phone, string $purpose, string $code): bool
    {
        $code = ArabicText::asciiDigits(trim($code));
        if ($this->isTestCode($code)) {
            Cache::forget($this->key($phone, $purpose));

            return true;
        }

        $key = $this->key($phone, $purpose);
        $entry = Cache::get($key);
        if (! is_array($entry) || $entry['attempts'] >= (int) config('zon.otp.max_attempts')) {
            return false;
        }
        if (! hash_equals($entry['hash'], hash('sha256', $code))) {
            Cache::put($key, ['hash' => $entry['hash'], 'attempts' => $entry['attempts'] + 1], now()->addMinutes((int) config('zon.otp.ttl_minutes')));

            return false;
        }
        Cache::forget($key);

        return true;
    }

    private function isTestCode(string $code): bool
    {
        $test = (string) config('zon.otp.test_code');

        return $test !== '' && hash_equals($test, $code) && ! app()->isProduction() && Drivers::of('sms') === Drivers::FAKE;
    }

    private function key(string $phone, string $purpose): string
    {
        return "otp:{$purpose}:{$phone}";
    }
}
