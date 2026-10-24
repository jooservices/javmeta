<?php

declare(strict_types=1);

return [
    // The newest movie must not be older than this, or the crawl is not moving.
    'max_newest_movie_age_minutes' => (int) env('JAVMETA_READINESS_NEWEST_MOVIE_MINUTES', 30),
    // How many enabled sources may have no success in 24 hours before the readiness report fails.
    'max_dead_sources' => (int) env('JAVMETA_READINESS_MAX_DEAD_SOURCES', 3),
    'max_backup_age_hours' => (int) env('JAVMETA_READINESS_BACKUP_HOURS', 26),
];
