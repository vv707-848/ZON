<?php

namespace App\Http\Middleware;

use App\Enums\CustomerRole;
use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Models\Customer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** A seeker enters only with a wish (428 PREFERENCE_REQUIRED → the app opens the wish form). */
class RequirePreference
{
    public function handle(Request $request, Closure $next): Response
    {
        $customer = $request->user('api');
        if ($customer instanceof Customer && $customer->role === CustomerRole::Seeker && ! $customer->preferences()->where('is_active', true)->exists()) {
            throw ApiException::of(ErrorCode::PreferenceRequired);
        }

        return $next($request);
    }
}
