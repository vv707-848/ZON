<?php

namespace App\Support;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Auth\Events\Authenticated;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Context;
use Laravel\Sanctum\Events\TokenAuthenticated;
use PDOException;
use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\State\Scope;

use function Sentry\configureScope;

/**
 * Sentry's hooks (config/sentry.php) and the account on each error:
 * - every error carries the trace id as `request_id`: the X-Request-Id of the user's action, the key to the same
 *   request in the logs, in «تتبّع طلب», in the app's own error and in the agents service's;
 * - the app's platform and version from its headers (X-App-Platform, X-App-Version);
 * - the account as a bare id — `customer:123` (the app, the website) or `staff:5` (the panel), never a name,
 *   a phone or an e-mail;
 * - a database error loses the value MySQL quotes in its own message (Duplicate entry '05…'); the statement is
 *   already masked (config/database.php mask_bindings_in_exception_messages).
 */
final class Monitoring
{
    public const TRACE_TAG = 'request_id';

    /** Log lines kept out of the breadcrumbs: the SMS simulator writes the code it would have sent. */
    private const QUIET_LOGS = ['sms.simulated'];

    private const HEADER_TAGS = ['X-App-Platform' => 'app.platform', 'X-App-Version' => 'app.version'];

    public static function beforeSend(Event $event, ?EventHint $hint = null): Event
    {
        $trace = Context::get(Trace::KEY);
        if (Trace::isSafe(is_string($trace) ? $trace : null)) {
            $event->setTag(self::TRACE_TAG, (string) $trace);
        }

        $request = request();   // a queued job or a command has the console's request, without these headers
        foreach (self::HEADER_TAGS as $header => $tag) {
            $value = $request->header($header);
            if (is_string($value) && preg_match('/^[A-Za-z0-9.+_-]{1,20}$/', $value) === 1) {
                $event->setTag($tag, $value);
            }
        }

        foreach ($event->getExceptions() as $exception) {
            if (is_a($exception->getType(), QueryException::class, true) || is_a($exception->getType(), PDOException::class, true)) {
                $exception->setValue(self::withoutValues($exception->getValue()));
            }
        }

        return $event;
    }

    public static function beforeBreadcrumb(Breadcrumb $breadcrumb): ?Breadcrumb
    {
        return in_array($breadcrumb->getMessage(), self::QUIET_LOGS, true) ? null : $breadcrumb;
    }

    /** The signed-in account on the errors of its request: a session (panel, website) or an app token. */
    public static function listen(Dispatcher $events): void
    {
        $events->listen(Authenticated::class, fn (Authenticated $event) => self::account($event->user));
        $events->listen(TokenAuthenticated::class, fn (TokenAuthenticated $event) => self::account($event->token->tokenable));
    }

    public static function account(mixed $user): void
    {
        $id = match (true) {
            $user instanceof Customer => 'customer:'.$user->getKey(),
            $user instanceof User => 'staff:'.$user->getKey(),
            default => null,
        };
        if ($id !== null) {
            configureScope(fn (Scope $scope) => $scope->setUser(['id' => $id]));
        }
    }

    /** MySQL quotes the offending value in its message: «Duplicate entry '0551234567' for key …», «Incorrect … value: 'x'». */
    public static function withoutValues(string $message): string
    {
        return (string) preg_replace("/\\b(entry|value:?)\\s+'(?:[^'\\\\]|\\\\.)*'/i", "\$1 '?'", $message);
    }
}
