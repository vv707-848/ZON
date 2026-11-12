<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\AuthenticateSession;
use Symfony\Component\HttpFoundation\Response;

/**
 * A website session ends when its account's password changes: «نسيت كلمة السر» signs out every other place — the
 * apps' tokens (ResetPassword), and here the website's sessions opened with the old password. Laravel's own
 * AuthenticateSession (a fingerprint of the password kept in the session), on the website's guard.
 */
final class AuthenticateSiteSession extends AuthenticateSession
{
    /** @param Closure(Request): Response $next */
    public function handle($request, Closure $next): mixed
    {
        $this->auth->shouldUse('site');

        return parent::handle($request, $next);
    }
}
