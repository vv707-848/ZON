<?php

namespace App\Http\Middleware;

use App\Enums\AccountStatus;
use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Models\Customer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** A suspended account is signed out of every phone at its next call; activity is noted every 5 minutes. */
class EnsureCustomerActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $customer = $request->user('api');
        if (! $customer instanceof Customer || $customer->anonymized_at !== null) {
            throw ApiException::of(ErrorCode::Unauthenticated);
        }
        if ($customer->status === AccountStatus::Suspended) {
            $customer->tokens()->delete();
            throw ApiException::of(ErrorCode::AccountSuspended);
        }
        if ($customer->last_seen_at === null || $customer->last_seen_at->lt(now()->subMinutes(5))) {
            $customer->forceFill(['last_seen_at' => now()])->saveQuietly();
        }

        return $next($request);
    }
}
