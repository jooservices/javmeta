<?php

declare(strict_types=1);

return [
    'startup' => [
        'enabled' => true,
        'timeout_seconds' => 2,
        'wait_seconds' => 60,
        'retry_backoff_seconds' => 1,
        'snapshot_ttl_seconds' => 5,
        'primary' => [
            'web' => ['postgres', 'redis', 'mongo', 'elasticsearch'],
            'worker' => ['postgres', 'redis', 'mongo'],
            'scheduler' => ['postgres', 'redis', 'mongo'],
        ],
        'defaults' => [
            'primary' => [
                'web' => ['postgres', 'redis', 'mongo', 'elasticsearch'],
                'worker' => ['postgres', 'redis', 'mongo'],
                'scheduler' => ['postgres', 'redis', 'mongo'],
            ],
        ],
        'command_allowlist' => [
            'about', 'completion', 'config:show', 'health:*', 'help', 'list', 'make:*',
            'migrate*', 'package:discover', 'route:list', 'settings:*', 'staging:readiness',
            'test*', 'tinker', 'vendor:publish',
        ],
        'command_denylist' => [],
        'flaresolverr_url' => '',
        'playwright_url' => '',
    ],
];
