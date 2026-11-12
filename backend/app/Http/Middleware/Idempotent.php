<?php

namespace App\Http\Middleware;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Models\Customer;
use App\Models\IdempotencyKey;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * «Idempotency-Key» (docs/api.md): a POST the app sends with this header runs once for the account, however often
 * it is sent — a retry after an answer was lost, or two taps at once. The first one runs; a repeat after it ended
 * gets the same answer (status and body, with «Idempotent-Replayed: true») and changes nothing; a repeat while it
 * still runs gets 409 IDEMPOTENCY_IN_PROGRESS; the same key with another body is 422 IDEMPOTENCY_MISMATCH. «First»
 * holds under concurrency: the unique (customer, key) lets one insert win. An answer that says nothing was done for
 * good (a 5xx, a 401, a 429) is not kept, so a retry runs again. A request without the header is not touched.
 */
class Idempotent
{
    public const HEADER = 'Idempotency-Key';

    /** Answers kept and replayed: the operation ended, done or refused for good. */
    private const KEPT_BELOW = 500;

    private const NOT_KEPT = [401, 408, 409, 425, 429];

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header(self::HEADER);
        $customer = $request->user('api');
        if (! $request->isMethod('POST') || ! is_string($key) || ! $customer instanceof Customer) {
            return $next($request);
        }
        if (preg_match('/^[A-Za-z0-9_-]{8,100}$/', $key) !== 1) {
            throw ApiException::of(ErrorCode::ValidationFailed, details: ['fields' => [self::HEADER => ['مفتاح العملية غير صالح.']]]);
        }
        $fingerprint = hash('sha256', $request->method().' '.$request->path().' '.json_encode($request->all()));

        try {
            $row = IdempotencyKey::query()->create(['customer_id' => $customer->id, 'key' => $key, 'fingerprint' => $fingerprint]);
        } catch (UniqueConstraintViolationException) {
            return $this->repeat(IdempotencyKey::query()->where('customer_id', $customer->id)->where('key', $key)->sole(), $fingerprint);
        }

        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            $row->delete();
            throw $e;
        }
        $status = $response->getStatusCode();
        if ($status >= self::KEPT_BELOW || in_array($status, self::NOT_KEPT, true)) {
            $row->delete();
        } else {
            $row->update(['status_code' => $status, 'response' => (string) $response->getContent()]);
        }

        return $response;
    }

    private function repeat(IdempotencyKey $row, string $fingerprint): Response
    {
        if (! hash_equals($row->fingerprint, $fingerprint)) {
            throw ApiException::of(ErrorCode::IdempotencyMismatch);
        }
        if ($row->status_code === null) {
            throw ApiException::of(ErrorCode::IdempotencyInProgress);
        }

        return JsonResponse::fromJsonString((string) $row->response, $row->status_code, ['Idempotent-Replayed' => 'true']);
    }
}
