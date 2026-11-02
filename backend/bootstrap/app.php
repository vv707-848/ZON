<?php

use App\Exceptions\ApiErrors;
use App\Exceptions\ApiException;
use App\Http\Middleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware as Stack;
use Illuminate\Http\Request;

// The three parties meet here: the app on /api/v1 (routes/api.php), the admin panel on /admin (Filament,
// app/Providers/Filament), the AI service only through App\Ai\AiGateway. Every request gets a trace id first.
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api/v1',
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
        ]);
        $middleware->api(prepend: [Middleware\CheckAppVersion::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontReport([ApiException::class]);
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());
        $exceptions->render(fn (Throwable $e, Request $request) => $request->is('api/*') ? ApiErrors::render($e) : null);
    })->create();
