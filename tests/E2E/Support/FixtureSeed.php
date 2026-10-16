<?php

declare(strict_types=1);

namespace Tests\E2E\Support;

final readonly class FixtureSeed
{
    public function __construct(public string $slug, public string $kind, public string $url, public string $bodyPath, public int $queueId) {}
}
