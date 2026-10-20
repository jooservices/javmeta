<?php

declare(strict_types=1);

use App\Observability\Sentry\SentryScrubber;

return [
    'dsn' => env('JAVMETA_SENTRY_DSN') ?: env('SENTRY_LARAVEL_DSN'),
    'release' => env('SENTRY_RELEASE'),
    'environment' => env('SENTRY_ENVIRONMENT', env('APP_ENV', 'production')),
    'sample_rate' => 1.0,
    'traces_sample_rate' => (float) env('JAVMETA_SENTRY_TRACES_SAMPLE_RATE', 0.0),
    'profiles_sample_rate' => 0.0,
    'send_default_pii' => false,
    'enable_logs' => false,
    'role' => env('JAVMETA_ROLE', 'api'),
    'node' => env('JAVMETA_NODE_NAME', 'unknown'),
    'before_send' => [SentryScrubber::class, 'beforeSend'],
    'before_breadcrumb' => [SentryScrubber::class, 'beforeBreadcrumb'],
    'breadcrumbs' => [
        'sql_bindings' => false,
    ],
    'tracing' => [
        'sql_bindings' => false,
    ],
];
