<?php

use App\Exceptions\ApiErrors;
use App\Exceptions\ApiException;
use App\Http\Middleware;
use App\Support\Site\Site;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware as Stack;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Sentry\Laravel\Integration as Sentry;

// The three parties meet here: the app on /api/v1 (routes/api.php), the admin panel on /admin (Filament,
// app/Providers/Filament), the AI service only through App\Ai\AiGateway. Every request gets a trace id first.
// The website (routes/site.php) answers on its own host, config zon.deep_link_base (https://zon.sa).
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api/v1',
        // The website on its own host (zon.sa), before routes/web.php's catch-alls would answer for it.
        then: fn () => Route::domain(Site::host())->group(base_path('routes/site.php')),
    )
    // Reverb's private channels, authorized with the app's token on /api/v1/broadcasting/auth.
    ->withBroadcasting(__DIR__.'/../routes/channels.php', ['prefix' => 'api/v1', 'middleware' => ['api', 'auth:api']])
    ->withMiddleware(function (Stack $middleware): void {
        $middleware->prepend(Middleware\AssignTraceId::class);
        $middleware->trustProxies(at: ['127.0.0.1']);   // nginx on the same node (deploy/nginx/zon.conf)
        $middleware->alias([
            'app.version' => Middleware\CheckAppVersion::class,
            'active' => Middleware\EnsureCustomerActive::class,
            'role' => Middleware\RequireRole::class,
            'preference' => Middleware\RequirePreference::class,
            'ai.quota' => Middleware\EnforceAiQuota::class,
            'site.headers' => Middleware\SiteHeaders::class,      // the website's forms (routes/site.php)
            'site.session' => Middleware\AuthenticateSiteSession::class,   // a password change ends the website's sessions
        ]);
        $middleware->api(prepend: [Middleware\CheckAppVersion::class]);
        // The website's reading pages: no session and no cookie (crawlers leave nothing), Arabic, security headers.
        $middleware->group('site', [SubstituteBindings::class, Middleware\SiteHeaders::class]);
        // auth:site sends a signed-out visitor to the website's sign-in (the panel and the API have their own).
        $middleware->redirectGuestsTo(fn () => Site::url('login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Unexpected errors go to Sentry (config/sentry.php; nothing is sent without SENTRY_LARAVEL_DSN). Expected
        // ones — ApiException, validation, 404, 401/403 — are not reported anywhere.
        Sentry::handles($exceptions);
        $exceptions->dontReport([ApiException::class]);
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());
        $exceptions->render(fn (Throwable $e, Request $request) => $request->is('api/*') ? ApiErrors::render($e) : null);
    })->create();
