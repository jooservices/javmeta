<?php

declare(strict_types=1);

namespace Tests\Feature\Operations;

use App\Models\CrawlQueue;
use App\Models\Movie;
use App\Models\Performer;
use App\Models\Source;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class OpsDashboardDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_data_endpoint_summarises_queue_runs_sources_issues_and_events(): void
    {
        $this->travelTo('2026-11-05T03:00:00Z');
        $now = now();
        Source::factory()->create(['slug' => 'fresh', 'name' => 'Fresh', 'priority' => 1, 'last_success_at' => $now->copy()->subMinutes(5)]);
        Source::factory()->create(['slug' => 'stale', 'name' => 'Stale', 'priority' => 2, 'last_success_at' => $now->copy()->subHours(2)]);
        Source::factory()->create([
            'slug' => 'broken', 'name' => 'Broken', 'priority' => 3,
            'last_success_at' => $now->copy()->subMinutes(10), 'last_error_at' => $now->copy()->subMinutes(1),
            'last_error' => str_repeat('boom ', 100),
        ]);
        Source::factory()->create(['slug' => 'never', 'name' => 'Never', 'priority' => 4, 'last_error_at' => $now->copy()->subMinutes(1)]);
        Source::factory()->create(['slug' => 'off', 'name' => 'Off', 'priority' => 5, 'enabled' => false]);

        CrawlQueue::factory()->count(2)->create(['source_slug' => 'fresh', 'status' => 'pending']);
        CrawlQueue::factory()->create(['source_slug' => 'fresh', 'status' => 'claimed']);
        CrawlQueue::factory()->create(['source_slug' => 'stale', 'status' => 'done']);
        CrawlQueue::factory()->create(['source_slug' => 'broken', 'status' => 'failed', 'kind' => 'detail', 'attempts' => 3, 'last_error' => str_repeat('x', 400)]);
        DB::table('crawl_queue')->where('status', 'failed')->update(['error_code' => 'http_403']);
        CrawlQueue::factory()->create(['source_slug' => 'never', 'status' => 'failed', 'kind' => 'listing']);
        CrawlQueue::factory()->create(['source_slug' => 'odd', 'status' => 'retrying']);

        DB::table('crawl_runs')->insert([
            ['source_slug' => 'fresh', 'started_at' => $now->copy()->subMinutes(30), 'status' => 'done', 'pages_fetched' => 4, 'movies_new' => 3, 'movies_updated' => 2, 'failures' => 1],
            ['source_slug' => 'fresh', 'started_at' => $now->copy()->subMinutes(10), 'status' => 'done', 'pages_fetched' => 6, 'movies_new' => 5, 'movies_updated' => 1, 'failures' => 0],
            ['source_slug' => 'fresh', 'started_at' => $now->copy()->subHours(3), 'status' => 'done', 'pages_fetched' => 100, 'movies_new' => 100, 'movies_updated' => 100, 'failures' => 100],
        ]);
        DB::table('crawl_events')->insert([
            ['source_slug' => 'fresh', 'kind' => 'listing_done', 'created_at' => $now->copy()->subMinutes(2)],
            ['source_slug' => 'broken', 'kind' => 'blocked', 'created_at' => $now->copy()->subMinutes(1)],
        ]);
        Movie::factory()->create(['first_seen_at' => $now->copy()->subMinutes(20)]);
        Movie::factory()->create(['first_seen_at' => $now->copy()->subHours(5)]);
        Performer::factory()->create();
        DB::table('failed_jobs')->insert(['uuid' => 'f-1', 'connection' => 'redis', 'queue' => 'detail', 'payload' => '{}', 'exception' => 'x', 'failed_at' => $now]);

        $admin = User::factory()->create(['role' => 'super_administrator']);
        $json = $this->actingAs($admin)->getJson('/ops/data')->assertOk()->assertHeader('Cache-Control')->json();

        self::assertSame($now->toIso8601String(), $json['as_of']);
        self::assertTrue($json['ui_login_required']);
        self::assertSame('Yêu cầu đăng nhập', $json['ui_login_state']);
        self::assertSame(['pending' => 2, 'claimed' => 1, 'done' => 1, 'failed' => 2, 'retrying' => 1], $json['queue']);
        self::assertSame(['runs' => 2, 'pages' => 10, 'movies_new' => 8, 'movies_updated' => 3, 'failures' => 1], $json['hour']);
        self::assertSame(2, $json['totals']['movies']);
        self::assertSame(1, $json['totals']['performers']);
        self::assertSame(1, $json['totals']['movies_first_seen_1h']);
        self::assertSame(1, $json['totals']['failed_jobs']);

        $sources = collect($json['sources'])->keyBy('slug');
        self::assertSame(['fresh', 'stale', 'broken', 'never'], $sources->keys()->all(), 'Only enabled sources, in priority order.');
        self::assertSame('healthy', $sources['fresh']['status']);
        self::assertSame(300, $sources['fresh']['last_success_age_seconds']);
        self::assertEquals(['pending' => 2, 'claimed' => 1], $sources['fresh']['queue']);
        self::assertSame('attention', $sources['stale']['status'], 'No success for more than 30 minutes.');
        self::assertSame('attention', $sources['broken']['status'], 'The latest error is newer than the latest success.');
        self::assertLessThanOrEqual(221, mb_strlen($sources['broken']['last_error']));
        self::assertSame('attention', $sources['never']['status']);
        self::assertNull($sources['never']['last_success_at']);
        self::assertNull($sources['never']['last_success_age_seconds']);
        self::assertSame(['failed' => 1], $sources['never']['queue']);
        self::assertSame(['done' => 1], $sources['stale']['queue']);

        $issues = collect($json['issues']);
        self::assertCount(2, $issues);
        self::assertSame('never', $issues[0]['source'], 'Newest failure first.');
        self::assertSame('unknown', $issues[0]['code']);
        self::assertSame('http_403', $issues[1]['code']);
        self::assertSame(3, $issues[1]['attempts']);
        self::assertLessThanOrEqual(221, mb_strlen($issues[1]['detail']));

        self::assertSame(['broken', 'fresh'], collect($json['events'])->pluck('source')->all(), 'Newest event first.');
    }

    public function test_an_empty_system_returns_zeroes_and_empty_lists(): void
    {
        $admin = User::factory()->create(['role' => 'super_administrator']);

        $json = $this->actingAs($admin)->getJson('/ops/data')->assertOk()->json();

        self::assertSame(['pending' => 0, 'claimed' => 0, 'done' => 0, 'failed' => 0], $json['queue']);
        self::assertSame(['runs' => 0, 'pages' => 0, 'movies_new' => 0, 'movies_updated' => 0, 'failures' => 0], $json['hour']);
        self::assertSame([], $json['sources']);
        self::assertSame([], $json['issues']);
        self::assertSame([], $json['events']);
    }

    public function test_the_data_endpoint_keeps_sources_healthy_at_the_thirty_minute_boundary(): void
    {
        $this->travelTo('2026-11-05T03:00:00Z');
        Source::factory()->create([
            'slug' => 'boundary',
            'name' => 'Boundary',
            'last_success_at' => now()->subMinutes(30),
        ]);
        $admin = User::factory()->create(['role' => 'super_administrator']);

        $json = $this->actingAs($admin)->getJson('/ops/data')->assertOk()->json();

        self::assertSame('healthy', $json['sources'][0]['status']);
        self::assertSame(1800, $json['sources'][0]['last_success_age_seconds']);
    }

    public function test_the_data_endpoint_returns_only_the_twelve_newest_issues_and_events(): void
    {
        $this->travelTo('2026-11-05T03:00:00Z');
        for ($index = 0; $index < 13; $index++) {
            CrawlQueue::factory()->create([
                'source_slug' => 'source',
                'status' => 'failed',
                'kind' => 'issue-' . $index,
            ]);
            DB::table('crawl_events')->insert([
                'source_slug' => 'source',
                'kind' => 'event-' . $index,
                'created_at' => now()->addSeconds($index),
            ]);
        }
        $admin = User::factory()->create(['role' => 'super_administrator']);

        $json = $this->actingAs($admin)->getJson('/ops/data')->assertOk()->json();

        self::assertCount(12, $json['issues']);
        self::assertSame('issue-12', $json['issues'][0]['kind']);
        self::assertSame('issue-1', $json['issues'][11]['kind']);
        self::assertCount(12, $json['events']);
        self::assertSame('event-12', $json['events'][0]['kind']);
        self::assertSame('event-1', $json['events'][11]['kind']);
    }
}
