<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Repositories\ReportDataServiceRepository;
use Illuminate\Support\Facades\DB;

final class ReportServiceStatsTest extends IntegrationTestCase
{
    public function test_postgres_server_stats_are_read_from_the_live_pg_activity_view(): void
    {
        self::assertSame('pgsql', DB::connection()->getDriverName());

        $stats = app(ReportDataServiceRepository::class)->postgresStats();

        self::assertIsArray($stats);
        self::assertGreaterThanOrEqual(0, $stats['used']);
        self::assertGreaterThan(0, $stats['maximum']);
        self::assertLessThanOrEqual($stats['maximum'], $stats['used']);
    }
}
