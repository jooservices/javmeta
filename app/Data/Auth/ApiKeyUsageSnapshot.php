<?php

declare(strict_types=1);

namespace App\Data\Auth;

/** Usage totals and busiest endpoints for one API key. */
final readonly class ApiKeyUsageSnapshot
{
    /**
     * @param array{calls: int, errors: int, avg_ms: int} $last24Hours
     * @param array{calls: int, errors: int, avg_ms: int} $last7Days
     * @param list<array{endpoint: string, calls: int}> $topEndpoints
     */
    public function __construct(
        public array $last24Hours,
        public array $last7Days,
        public array $topEndpoints,
    ) {}

    /** @return array{last_24h: array{calls: int, errors: int, avg_ms: int}, last_7d: array{calls: int, errors: int, avg_ms: int}, top_endpoints: list<array{endpoint: string, calls: int}>} */
    public function toArray(): array
    {
        return [
            'last_24h' => $this->last24Hours,
            'last_7d' => $this->last7Days,
            'top_endpoints' => $this->topEndpoints,
        ];
    }
}
