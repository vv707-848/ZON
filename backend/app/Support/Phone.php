<?php

namespace App\Support;

/**
 * Saudi mobile numbers. Stored as E.164 (+9665XXXXXXXX), one per account; the app shows and sends 05XXXXXXXX
 * (lib/core/utils/input.dart normalizeSaudiMobile — the same accepted forms, Arabic digits included).
 */
final class Phone
{
    /** E.164, or null when it is not a Saudi mobile number. */
    public static function normalize(?string $input): ?string
    {
        if ($input === null) {
            return null;
        }
        $digits = (string) preg_replace('/[\s\-().]/u', '', ArabicText::asciiDigits($input));
        $digits = match (true) {
            str_starts_with($digits, '+966') => '0'.substr($digits, 4),
            str_starts_with($digits, '00966') => '0'.substr($digits, 5),
            str_starts_with($digits, '966') => '0'.substr($digits, 3),
            strlen($digits) === 9 && str_starts_with($digits, '5') => '0'.$digits,
            default => $digits,
        };

        return preg_match('/^05\d{8}$/', $digits) === 1 ? '+966'.substr($digits, 1) : null;
    }

    /** The app's form: 05XXXXXXXX. */
    public static function local(string $e164): string
    {
        return str_starts_with($e164, '+966') ? '0'.substr($e164, 4) : $e164;
    }

    /** For logs and the admin panel's lists: 05•••••123. */
    public static function masked(string $e164): string
    {
        $local = self::local($e164);

        return substr($local, 0, 2).str_repeat('•', max(0, strlen($local) - 5)).substr($local, -3);
    }
}
