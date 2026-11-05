<?php

use App\Support\Monitoring;

/**
 * Error tracking (Sentry) for the app's API, the admin panel, the website, the queues and the scheduler.
 * Off while SENTRY_LARAVEL_DSN is empty. App\Support\Monitoring adds the trace id and scrubs what is sent.
 *
 * What an error carries: the exception, its stack (code lines, no variable values), the route, the trace id
 * (X-Request-Id, the same as in the logs and «تتبّع طلب»), the app's platform and version, and the account as a
 * bare id ("customer:123"). Never: request or response bodies, cookies, query strings, SQL values, queue
 * payloads, names, phones or IP addresses. docs/operations.md «تتبّع الأخطاء».
 *
 * @see https://docs.sentry.io/platforms/php/guides/laravel/configuration/options/
 */
$agentsHost = parse_url((string) env('AGENTS_URL', 'http://127.0.0.1:8100'), PHP_URL_HOST);

return [

    'dsn' => env('SENTRY_LARAVEL_DSN'),

    // The deployed commit (deploy sets it: SENTRY_RELEASE=zon-backend@<git sha>), so an error names its version.
    'release' => env('SENTRY_RELEASE'),

    // Empty → APP_ENV.
    'environment' => env('SENTRY_ENVIRONMENT'),

    'sample_rate' => 1.0,

    // Performance traces cost quota; errors are what we need. 0 keeps traces off; 0.05 samples 5% of requests.
    'traces_sample_rate' => (float) env('SENTRY_TRACES_SAMPLE_RATE', 0),
    'profiles_sample_rate' => null,

    // The trace continues only into the agents service (one trace across the server and the AI). The payment,
    // SMS, store and Google calls get no Sentry header.
    'trace_propagation_targets' => is_string($agentsHost) ? [$agentsHost] : [],

    'send_default_pii' => false,

    // What the SDK may collect at all — explicit, so a future default cannot widen it.
    'data_collection' => [
        'user_info' => false,                       // the bare account id is set by Monitoring, nothing else
        'cookies' => ['mode' => 'off'],
        'http_headers' => [
            'request' => ['mode' => 'allowList', 'terms' => ['Accept', 'Accept-Language', 'Content-Type', 'User-Agent',
                'X-App-Platform', 'X-App-Version', 'X-Request-Id']],
            'response' => ['mode' => 'off'],
        ],
        'http_bodies' => [],
        'url_query_params' => ['mode' => 'off'],
        'gen_ai' => ['inputs' => false, 'outputs' => false],
        'database_query_data' => false,
        'queues' => false,
        'stack_frame_variables' => false,
        'frame_context_lines' => 5,
    ],

    'before_send' => [Monitoring::class, 'beforeSend'],
    'before_breadcrumb' => [Monitoring::class, 'beforeBreadcrumb'],

    'enable_logs' => false,
    'enable_metrics' => false,

    'ignore_transactions' => ['/up'],

    'breadcrumbs' => [
        'logs' => true,                    // our log lines carry ids and timings, never text (Monitoring drops the SMS simulator's)
        'cache' => false,                  // cache keys may name a phone number (OTP throttles)
        'livewire' => true,
        'sql_queries' => true,             // the statement with ? placeholders
        'sql_bindings' => false,
        'queue_info' => true,
        'command_info' => true,
        'http_client_requests' => true,    // method, host and path, and the status
        'notifications' => true,
    ],

    'tracing' => [
        'queue_job_transactions' => true,
        'queue_jobs' => true,
        'sql_queries' => true,
        'sql_bindings' => false,
        'sql_origin' => true,
        'sql_origin_threshold_ms' => 100,
        'views' => true,
        'livewire' => true,
        'http_client_requests' => true,
        'http_client_requests_origin' => true,
        'http_client_requests_origin_threshold_ms' => 250,
        'cache' => false,
        'redis_commands' => false,
        'redis_origin' => false,
        'notifications' => true,
        'missing_routes' => false,
        'continue_after_response' => true,
        'gen_ai' => false,
        'default_integrations' => true,
    ],

];
