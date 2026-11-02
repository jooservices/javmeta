<?php

declare(strict_types=1);

namespace Tests\Unit\Crawl;

use App\Services\Crawl\CrawlStatsAggregator;
use PHPUnit\Framework\TestCase;

final class CrawlStatsAggregatorTest extends TestCase
{
    public function test_it_classifies_attempts_and_calculates_average_and_p90_duration(): void
    {
        $attempts = [
            $this->buildAttempt('ok', 10, 200),
            $this->buildAttempt('error', 20, 403),
            $this->buildAttempt('error', 30, 429),
            $this->buildAttempt('error', 40, 200, 'challenge'),
            $this->buildAttempt('error', 50, 504, errorCode: 'request_timeout'),
            $this->buildAttempt('error', 60, 500),
            $this->buildAttempt('skipped', 70, null),
        ];

        $result = (new CrawlStatsAggregator())->aggregate($attempts);
        $group = $result['groups'][0];

        self::assertSame(7, $group['attempts']);
        self::assertSame(1, $group['ok']);
        self::assertSame(3, $group['blocked']);
        self::assertSame(1, $group['timeout']);
        self::assertSame(1, $group['other_error']);
        self::assertSame(1, $group['skipped']);
        self::assertSame(40.0, $group['avg_duration_ms']);
        self::assertSame(70, $group['p90_duration_ms']);
        self::assertSame(7, $result['hours'][0]['attempts']);
    }

    /** @return array<string, mixed> */
    private function buildAttempt(string $outcome, int $duration, ?int $status, ?string $cfMitigated = null, ?string $errorCode = null): array
    {
        return [
            'source_slug' => 'onejav',
            'lane' => 'browser',
            'method' => 'playwright',
            'started_at' => '2026-11-02 10:15:00+00:00',
            'duration_ms' => $duration,
            'http_status' => $status,
            'cf_mitigated' => $cfMitigated,
            'outcome' => $outcome,
            'error_code' => $errorCode,
        ];
    }
}
