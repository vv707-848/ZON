<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** /admin only from ADMIN_ALLOWED_IPS when set (zon:doctor warns when it is empty in production). */
class RestrictAdminIps
{
    public function handle(Request $request, Closure $next): Response
    {
        $allowed = (array) config('zon.admin_allowed_ips');
        abort_if($allowed !== [] && ! in_array($request->ip(), $allowed, true), 403);

        return $next($request);
    }
}
