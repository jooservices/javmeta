<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Default channels
    |--------------------------------------------------------------------------
    |
    | Channel names used when Notification::send() is called without an
    | explicit channels list. Each name must match a registered adapter.
    |
    */
    'default_channels' => ['telegram'],

    'channels' => [
        'telegram' => [
            'bot_token' => '',
            'chat_id' => '',
            'api_base' => 'https://api.telegram.org',
            'timeout' => 5.0,
        ],

        'slack' => [
            'webhook_url' => env('NOTIFICATION_SLACK_WEBHOOK_URL', ''),
            'timeout' => (float) env('NOTIFICATION_SLACK_TIMEOUT', 5),
        ],

        'mail' => [
            'mailer' => env('NOTIFICATION_MAIL_MAILER'),
            'to' => array_values(array_filter(array_map(
                'trim',
                explode(',', (string) env('NOTIFICATION_MAIL_TO', '')),
            ))),
            'from' => [
                'address' => env('NOTIFICATION_MAIL_FROM_ADDRESS', env('MAIL_FROM_ADDRESS')),
                'name' => env('NOTIFICATION_MAIL_FROM_NAME', env('MAIL_FROM_NAME')),
            ],
        ],
    ],
];
