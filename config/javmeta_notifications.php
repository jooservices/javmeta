<?php

declare(strict_types=1);

// Defaults only: the values are settings in the database (docs/03-operations/settings.md) and are edited in the UI.
return [
    'follow' => [
        'enabled' => true,
        'daily_cap' => 30,
        'quiet_from_hour' => 23,
        'quiet_to_hour' => 7,
        'lookback_days' => 2,
    ],
];
