<?php

declare(strict_types=1);

// Defaults only: the values are settings in the database (docs/03-operations/settings.md) and are edited in the UI.
return [
    /** Language of Telegram/Alertmanager templates when sending (en|vi). Default en keeps current English text. */
    'locale' => 'en',
    'policy' => [
        'quiet_mode' => false,
    ],
    'bot' => [
        'enabled' => true,
        'max_results' => 5,
    ],
    'follow' => [
        'enabled' => true,
        'daily_cap' => 30,
        'quiet_from_hour' => 23,
        'quiet_to_hour' => 7,
        'lookback_days' => 2,
    ],
];
