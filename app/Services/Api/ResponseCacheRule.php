<?php

declare(strict_types=1);

namespace App\Services\Api;

final readonly class ResponseCacheRule
{
    /**
     * @param list<string> $queryKeys
     * @param array<string, int|string|bool> $defaults
     */
    public function __construct(
        public string $class,
        public array $queryKeys,
        public array $defaults = [],
        public ?int $maxPerPage = null,
    ) {}
}
