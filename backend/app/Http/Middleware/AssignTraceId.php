<?php

namespace App\Http\Middleware;

use App\Support\Trace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Gives every request its trace id (App\Support\Trace) and returns it in X-Request-Id. */
class AssignTraceId
{
    public function handle(Request $request, Closure $next): Response
    {
        $id = Trace::start($request->header(Trace::HEADER));
        $response = $next($request);
        $response->headers->set(Trace::HEADER, $id);

        return $response;
    }
}
