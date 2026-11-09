<?php

declare(strict_types=1);

namespace App\Data\Downloads;

use JOOservices\Dto\Core\Dto;

final class DownloadSearchStartData extends Dto
{
    public function __construct(
        public readonly DownloadSummaryData $summary,
        public readonly bool $queued,
        public readonly ?string $reason,
    ) {}
}
