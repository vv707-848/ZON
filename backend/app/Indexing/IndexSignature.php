<?php

namespace App\Indexing;

/** X-Signature of an index signal: sha256=hex(HMAC_SHA256(secret, timestamp + "." + body)) — contract §4. */
final class IndexSignature
{
    /** @return array{X-Timestamp: string, X-Signature: string} */
    public static function headers(string $body, ?int $timestamp = null): array
    {
        $timestamp ??= time();

        return [
            'X-Timestamp' => (string) $timestamp,
            'X-Signature' => 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, (string) config('zon.index.secret')),
        ];
    }
}
