<?php

declare(strict_types=1);

return [
    /*
    | Master on/off switch. When false, AlertDispatcher skips every alert
    | (no fan-out to Telegram / activity log). Default: on.
    */
    'enabled' => true,
    'channels' => ['telegram'],
    'evaluate_interval_seconds' => 60,
    'window_minutes' => 15,
    'success_rate_min_percent' => 85,
    'success_rate_critical_percent' => 70,
    'blocked_ratio_max_percent' => 10,
    'pg_connections_max' => 45,
    'postgres_connection_budget_warning_percent' => 75,
    'dispatch_age_max_seconds' => 60,
    'backlog_due_min_per_hot_source' => 10,
    'min_attempts_in_window' => 100,
    'cooldown_minutes' => 30,
    'recovery_notice' => true,
    'recovery_retry_max_attempts' => 3,
    'history_retention_days' => 90,
    'webhook_url' => '',
    'webhook_timeout_seconds' => 5,

    /*
    | Telegram credentials prefer jooservices/laravel-notifications config /
    | NOTIFICATION_TELEGRAM_* env, with TELEGRAM_* (below) as legacy fallback.
    */
    'telegram' => [
        'owner_user_id' => null,
        'bot_token' => '',
        'chat_id' => '',
        'api_base' => 'https://api.telegram.org',
        'timeout' => 5,
    ],
    // Existing local-time behavior for follow notifications and library scans.
    'hourly_report' => [
        'timezone' => 'Asia/Ho_Chi_Minh',
        'state_path' => storage_path('app/private/reports/hourly.json'),
    ],
    'debounce_seconds' => 900,
    // At most one alert per cause per this many seconds (watchdog, backup, stalled crawl, stuck queue).
    'throttle_seconds' => 3600,
    'miss_debounce_seconds' => 3600,
    'title_failure_threshold' => 3,
    'watchdog' => [
        'heartbeat_stale_seconds' => 600,
        'source_stale_seconds' => 86400,
        'crawl_stall_seconds' => 1800,
        'queue_stuck_seconds' => 1800,
        'pending_queue_limit' => 1000,
        'crawl_dispatch_warning_seconds' => 60,
        'crawl_dispatch_critical_seconds' => 180,
        'stall_after_minutes' => 45,
        'temp_data_max_age_hours' => 24,
    ],
];
