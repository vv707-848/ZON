<?php

namespace App\Http\Middleware;

use App\Enums\CustomerRole;
use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Models\Customer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** `role:broker,developer` — the services of the account page each role can open (the app's kSvcRoles). */
class RequireRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $customer = $request->user('api');
        $allowed = array_map(fn (string $role) => CustomerRole::from($role), $roles);
        if (! $customer instanceof Customer || ! $customer->hasRole(...$allowed)) {
            throw ApiException::of(ErrorCode::RoleForbidden);
        }

        return $next($request);
    }
}
