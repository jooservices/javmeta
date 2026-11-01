<?php

declare(strict_types=1);

return [
    /*
    | Master on/off switch. When false, AlertDispatcher skips every alert
    | (no fan-out to Telegram / activity log). Default: on.
    */
    'enabled' => true,

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
    // Hourly Telegram report: totals now and the change since the previous report (javmeta:hourly-report).
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
        'stall_after_minutes' => 45,
        'temp_data_max_age_hours' => 24,
    ],
];
