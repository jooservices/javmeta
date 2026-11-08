<?php

declare(strict_types=1);

return [
    'fetch_attempts_retention_days' => 14,
    'egress_probe_urls' => [
        'https://ifconfig.me',
        'https://icanhazip.com',
        'https://checkip.amazonaws.com',
        'https://api.ipify.org',
    ],
    'egress_probe_interval_seconds' => 120,
    'browser_egress_probe_interval_seconds' => 900,
    'egress_probe_timeout_seconds' => 5,
    'egress_changes_stream' => 'xcrawler_logs',
    'record_egress_ip' => true,
];
