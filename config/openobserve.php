<?php

declare(strict_types=1);

return [
    'enabled' => (bool) env('OPENOBSERVE_ENABLED', false),
    'url' => rtrim((string) env('OPENOBSERVE_URL', 'http://openobserve:5080'), '/'),
    'org' => (string) env('OPENOBSERVE_ORG', 'default'),
    'stream_logs' => (string) env('OPENOBSERVE_STREAM_LOGS', 'javmeta_logs'),
    'stream_metrics' => (string) env('OPENOBSERVE_STREAM_METRICS', 'javmeta_metrics'),
    'email' => (string) env('OPENOBSERVE_EMAIL', ''),
    'password' => (string) env('OPENOBSERVE_PASSWORD', ''),
    'timeout' => (int) env('OPENOBSERVE_TIMEOUT', 3),
    'trace_sample_rate' => (float) env('OPENOBSERVE_TRACE_SAMPLE_RATE', 1.0),
    'service_name' => (string) env('OPENOBSERVE_SERVICE_NAME', env('APP_NAME', 'javmeta')),
];
