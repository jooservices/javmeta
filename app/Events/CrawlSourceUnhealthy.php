<?php

declare(strict_types=1);

namespace App\Events;

final class CrawlSourceUnhealthy
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly string $sourceSlug,
        public readonly string $reason,
        public readonly array $context = [],
    ) {}

    public function alertKey(): string
    {
        return self::keyFor($this->sourceSlug, $this->reason);
    }

    public static function keyFor(string $sourceSlug, string $reason): string
    {
        return "source:{$sourceSlug}:{$reason}";
    }
}
