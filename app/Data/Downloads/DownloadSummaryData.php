<?php

declare(strict_types=1);

namespace App\Data\Downloads;

use App\Support\Downloads\DownloadSearchState;
use JOOservices\Dto\Core\Dto;

final class DownloadSummaryData extends Dto
{
    public function __construct(
        public bool $available,
        public int $count,
        public ?string $last_checked_at,
        public string $state,
        public ?string $cooldown_until = null,
        public bool $search_enabled = true,
    ) {}

    public static function idle(int $count = 0, bool $searchEnabled = true): self
    {
        return new self(
            available: $count > 0,
            count: $count,
            last_checked_at: null,
            state: $count > 0 ? DownloadSearchState::FOUND->value : DownloadSearchState::IDLE->value,
            search_enabled: $searchEnabled,
        );
    }
}
