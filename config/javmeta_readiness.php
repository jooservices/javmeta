<?php

declare(strict_types=1);

return [
    // The newest movie must not be older than this, or the crawl is not moving.
    'max_newest_movie_age_minutes' => 30,
    // How many enabled sources may be past due under their configured crawl schedule before readiness fails.
    'max_dead_sources' => 3,
    'max_backup_age_hours' => 26,
];
