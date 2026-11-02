<?php

namespace App\Support;

use Closure;

/**
 * Answers computed once per request or queued job (blocks, entitlements) — emptied when a request is handled
 * and before each job (AppServiceProvider), so a long-lived process never serves yesterday's answer.
 */
final class RequestCache
{
    /** @var array<string, mixed> */
    private static array $items = [];

    /**
     * @template T
     *
     * @param  Closure(): T  $make
     * @return T
     */
    public static function remember(string $key, Closure $make): mixed
    {
        if (! array_key_exists($key, self::$items)) {
            self::$items[$key] = $make();
        }

        return self::$items[$key];
    }

    public static function forget(string $key): void
    {
        unset(self::$items[$key]);
    }

    public static function flush(): void
    {
        self::$items = [];
    }
}
