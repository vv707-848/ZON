<?php

namespace App\Http\Middleware;

use App\Ai\Quota\AiQuota;
use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Services\Billing\Entitlements;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `ai.quota:search|matching|summary|chat` — no cap in the plan → 402 PAYMENT_REQUIRED; cap reached → 429
 * QUOTA_EXCEEDED; both with the plans that give more. Answers carry X-AI-Limit · X-AI-Remaining · X-AI-Resets-At.
 */
class EnforceAiQuota
{
    public function __construct(private readonly AiQuota $quota) {}

    public function handle(Request $request, Closure $next, string $use): Response
    {
        $customer = $request->user('api');
        $cap = Entitlements::for($customer)->aiDaily($use);
        if ($cap <= 0) {
            throw ApiException::of(ErrorCode::PaymentRequired, ['upgrade' => AiQuota::offers($customer, $use)]);
        }

        if (! $this->quota->attempt($use, $customer, $cap)) {
            throw ApiException::of(ErrorCode::TooManyRequests, ['resets_at' => AiQuota::resetsAt()->toIso8601String()]);
        }
        $reservation = $this->quota->reserve($use, $customer, $request->header('X-Device-Id'), $request->ip(), $cap);
        if ($reservation === null) {
            throw ApiException::of(ErrorCode::QuotaExceeded, ['upgrade' => AiQuota::offers($customer, $use), 'resets_at' => AiQuota::resetsAt()->toIso8601String()]);
        }

        $response = $next($request);
        if (! AiQuota::consumed()) {
            $reservation->release();
        }

        $response->headers->add([
            'X-AI-Limit' => (string) $cap,
            'X-AI-Remaining' => (string) max(0, $reservation->remaining),
            'X-AI-Resets-At' => AiQuota::resetsAt()->toIso8601String(),
        ]);

        return $response;
    }
}
