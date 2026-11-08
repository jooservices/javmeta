<?php

declare(strict_types=1);

return [
    'enabled' => false,
    'url' => rtrim((string) env('OPENOBSERVE_URL', 'http://openobserve:5080'), '/'),
    'org' => 'default',
    'stream_logs' => 'javmeta_logs',
    'stream_metrics' => 'javmeta_metrics',
    'email' => (string) env('OPENOBSERVE_EMAIL', ''),
    'password' => (string) env('OPENOBSERVE_PASSWORD', ''),
    'timeout' => 3,
    'trace_sample_rate' => 1.0,
    'service_name' => 'javmeta',
];
