<?php

declare(strict_types=1);

namespace App\Data\Downloads;

use JOOservices\Dto\Core\Dto;

final class MovieDownloadSearchData extends Dto
{
    /**
     * @param list<string> $sources_queued
     * @param list<string> $sources_done
     */
    public function __construct(
        public readonly ?int $id,
        public readonly int $movie_id,
        public readonly string $state,
        public readonly int $found_count,
        public readonly array $sources_queued = [],
        public readonly array $sources_done = [],
        public readonly ?string $last_checked_at = null,
        public readonly ?string $cooldown_until = null,
        public readonly ?string $started_at = null,
        public readonly ?string $last_error = null,
    ) {}
}
