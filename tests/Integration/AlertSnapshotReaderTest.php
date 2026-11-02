<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\Alerts\DatabaseAlertSnapshotReader;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class AlertSnapshotReaderTest extends IntegrationTestCase
{
    public function test_postgres_snapshot_excludes_skipped_attempts_and_counts_blocked_and_due_rows(): void
    {
        self::assertSame('pgsql', DB::connection()->getDriverName());
        DB::table('sources')->insert([
            'slug' => 'alert-source',
            'name' => 'Alert source',
            'base_url' => 'https://example.test',
            'enabled' => true,
            'priority' => 1,
            'gap_seconds_default' => 1,
            'gap_seconds_current' => 1,
            'last_success_at' => now(),
        ]);

        foreach ([
            ['status' => 200, 'outcome' => 'ok', 'mitigated' => null],
            ['status' => 403, 'outcome' => 'error', 'mitigated' => null],
            ['status' => 500, 'outcome' => 'error', 'mitigated' => 'challenge'],
            ['status' => 200, 'outcome' => 'skipped', 'mitigated' => null],
        ] as $attempt) {
            DB::table('crawl_fetch_attempts')->insert([
                'created_at' => now(),
                'source_slug' => 'alert-source',
                'url_hash' => hash('sha256', 'https://example.test/' . $attempt['outcome'] . $attempt['status']),
                'method' => 'http',
                'started_at' => now(),
                'duration_ms' => 1,
                'http_status' => $attempt['status'],
                'cf_mitigated' => $attempt['mitigated'],
                'outcome' => $attempt['outcome'],
            ]);
        }

        DB::table('crawl_queue')->insert([
            'source_slug' => 'alert-source',
            'url' => 'https://example.test/due',
            'kind' => 'movie',
            'status' => 'pending',
            'next_attempt_at' => now()->subMinute(),
        ]);
        DB::table('crawl_queue')->insert([
            'source_slug' => 'alert-source',
            'url' => 'https://example.test/future',
            'kind' => 'movie',
            'status' => 'pending',
            'next_attempt_at' => now()->addMinute(),
        ]);

        $snapshot = app(DatabaseAlertSnapshotReader::class)->snapshot(CarbonImmutable::now()->subMinutes(15));

        self::assertSame(['attempts' => 3, 'successes' => 1, 'blocked' => 2], $snapshot['attempts_by_source']['alert-source']);
        self::assertContains('alert-source', $snapshot['enabled_sources']);
        self::assertContains('alert-source', $snapshot['hot_sources']);
        self::assertSame(['alert-source' => 1], $snapshot['due_by_source']);
        self::assertTrue($snapshot['has_due_work']);
        self::assertNotNull($snapshot['postgres_connections']);
    }
}
