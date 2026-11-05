<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every page of the website: Arabic, and headers that keep it from being framed, sniffed or used to load anything
 * but its own files and the photos' host. HSTS and nosniff are also set by nginx (deploy/nginx/zon.conf).
 * A page that needs more (the payment form) widens the policy in its own response.
 */
class SiteHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        app()->setLocale('ar');

        $response = $next($request);
        $media = parse_url((string) config('filesystems.disks.minio.temporary_url'), PHP_URL_HOST);
        $images = "'self' data:".($media ? " https://{$media}" : '');

        $headers = [
            'Content-Security-Policy' => "default-src 'self'; img-src {$images}; style-src 'self'; script-src 'self'; "
                ."object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'",
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=()',
            'Content-Language' => 'ar',
        ];
        foreach ($headers as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }
}
