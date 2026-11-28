<?php

declare(strict_types=1);

return [
    'ui_login_required' => true,
    'ui_guest_rate_limit_per_minute' => 120,
    /** Owner UI language (vi|en). The browser also keeps a localStorage copy for guests. */
    'ui_locale' => 'vi',
    /** The built React UI served at the root of the site (npm run build in ui/ writes it). */
    'ui_index_path' => public_path('web/index.html'),
];
