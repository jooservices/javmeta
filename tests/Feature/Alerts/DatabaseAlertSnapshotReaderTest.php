<?php

declare(strict_types=1);

namespace Tests\Feature\Alerts;

use App\Models\Source;
use App\Services\Alerts\DatabaseAlertSnapshotReader;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class DatabaseAlertSnapshotReaderTest extends TestCase
{
    use RefreshDatabase;

    public function test_snapshot_excludes_skipped_attempts_and_counts_blocked_and_due_rows(): void
    {
        Source::factory()->create([
            'slug' => 'alert-source',
            'enabled' => true,
            'last_success_at' => now(),
        ]);

        foreach ([
            ['status' => 200, 'outcome' => 'ok', 'mitigated' => null],
            ['status' => 429, 'outcome' => 'error', 'mitigated' => null],
            ['status' => 503, 'outcome' => 'error', 'mitigated' => 'challenge'],
            ['status' => 200, 'outcome' => 'skipped', 'mitigated' => null],
        ] as $attempt) {
            DB::table('crawl_fetch_attempts')->insert([
                'created_at' => now(),
                'source_slug' => 'alert-source',
                'url_hash' => hash('sha256', $attempt['outcome'] . $attempt['status']),
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
        DB::table('crawl_fetch_attempts')->insert([
            'created_at' => now(),
            'source_slug' => 'alert-source',
            'url_hash' => hash('sha256', 'future-attempt'),
            'method' => 'http',
            'started_at' => now()->addHour(),
            'duration_ms' => 1,
            'http_status' => 200,
            'outcome' => 'ok',
        ]);

        $snapshot = app(DatabaseAlertSnapshotReader::class)->snapshot(CarbonImmutable::now()->subMinutes(15));

        self::assertSame(['attempts' => 3, 'successes' => 1, 'blocked' => 2], $snapshot['attempts_by_source']['alert-source']);
        self::assertContains('alert-source', $snapshot['enabled_sources']);
        self::assertContains('alert-source', $snapshot['hot_sources']);
        self::assertSame(['alert-source' => 1], $snapshot['due_by_source']);
        self::assertTrue($snapshot['has_due_work']);
        self::assertNull($snapshot['postgres_connections']);
    }
}
