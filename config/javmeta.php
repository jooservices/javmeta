<?php

declare(strict_types=1);

return [
    'ui_login_required' => (bool) env('JAVMETA_UI_LOGIN_REQUIRED', true),
    'ui_guest_rate_limit_per_minute' => 120,
];
