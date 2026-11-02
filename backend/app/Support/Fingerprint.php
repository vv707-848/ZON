<?php

namespace App\Support;

/** A salted HMAC of a device id or an IP address: lets us count per device / IP without keeping either (§14.2). */
final class Fingerprint
{
    public static function of(?string $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        return hash_hmac('sha256', $raw, (string) (config('zon.rate_salt') ?: config('app.key')));
    }
}
