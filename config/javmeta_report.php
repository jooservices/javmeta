<?php

declare(strict_types=1);

return [
    'enabled' => true,
    'interval_minutes' => 120,
    'timezone' => 'America/Mexico_City',
    'daily_time' => '08:00',
    'daily_timezone' => 'America/Mexico_City',
    'legacy_hourly_enabled' => false,
    'quiet_hours_start' => '',
    'quiet_hours_end' => '',
    'sections' => ['movies', 'performers', 'crawl', 'sources', 'fleet', 'workers', 'servers', 'services'],
    'top_sources_limit' => 5,
    'channels' => ['telegram'],
    'history_days' => 30,
    'worker_busy_ttl_seconds' => 3600,
    'sample_retention_minutes' => 180,
    'sample_max_age_seconds' => 180,
    'node_volume_path' => '',
    'browser_stats_url' => '',
    'template' => "{header}\n\n{movies}\n\n{performers}\n\n{crawl}\n\n{sources}\n{fleet}\n\n{workers}\n\n{servers}\n{services}\n\n{notification_events}\n{deploys}\n{backup}\n{warnings}",
];
