<?php

declare(strict_types=1);

namespace App\Events;

use DateTimeInterface;

final class SourceCircuitOpened
{
    public function __construct(
        public readonly string $sourceSlug,
        public readonly int $consecutiveBlocks,
        public readonly int $cooldownSeconds,
        public readonly DateTimeInterface $resumeAt,
        public readonly int $openCount = 1,
    ) {}
}
