<?php

declare(strict_types=1);

namespace App\Data\Persist;

use App\Models\Movie;

/** The persisted movie and its crawl outcome flags. */
final readonly class MoviePersistOutcome
{
    public function __construct(
        public Movie $movie,
        public bool $isNewMovie,
        public bool $sharpDrop,
        public int $drop,
    ) {}
}
