<?php

declare(strict_types=1);

return [
    'ui_login_required' => true,
    'ui_guest_rate_limit_per_minute' => 120,
    /** Owner UI language (vi|en). The browser also keeps a localStorage copy for guests. */
    'ui_locale' => 'vi',
    /** HTTP date after which the legacy UI bridges (/ui-api, /ui-me, /ui-admin) go away; empty until the owner fixes one. */
    'legacy_ui_sunset' => null,
];
