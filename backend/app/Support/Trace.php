<?php

namespace App\Support;

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;

/**
 * One trace id for everything a single user action causes, across the three parties:
 * - **the app:** X-Request-Id on every response, `error.trace_id` in every error;
 * - **the admin panel:** the same header on its requests;
 * - **the AI layer:** sent as X-Request-Id to the agents service, stored on ai_requests and index_events.
 *
 * Laravel Context puts it on every log line and carries it into queued jobs. To follow an action: the admin
 * page «تتبّع طلب», or grep the id in the Laravel, nginx and agents service logs.
 */
final class Trace
{
    public const KEY = 'trace_id';

    public const HEADER = 'X-Request-Id';

    /** What an id from outside must look like to be kept (a ULID, a UUID, any short safe token). */
    public const PATTERN = '/^[A-Za-z0-9._-]{8,64}$/';

    /** Starts the trace of an incoming request; keeps the caller's id when it is a safe token. */
    public static function start(?string $incoming): string
    {
        $id = self::isSafe($incoming) ? $incoming : (string) Str::ulid();
        Context::add(self::KEY, $id);

        return $id;
    }

    /** @phpstan-assert-if-true string $id */
    public static function isSafe(?string $id): bool
    {
        return $id !== null && preg_match(self::PATTERN, $id) === 1;
    }

    /** The current trace id; scheduled runs and console commands get a fresh one on first use. */
    public static function id(): string
    {
        $id = Context::get(self::KEY);

        return is_string($id) ? $id : self::start(null);
    }
}
