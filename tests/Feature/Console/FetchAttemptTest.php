<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class FetchAttemptTest extends TestCase
{
    use RefreshDatabase;

    public function test_prune_preserves_boundary_and_recent_rows(): void
    {
        $this->freezeTime();
        config()->set('javmeta_observability.fetch_attempts_retention_days', 3);
        $this->insert(now()->subDays(3)->subSecond(), 'expired');
        $this->insert(now()->subDays(3), 'boundary');
        $this->insert(now(), 'recent');
        $this->artisan('crawler:prune-fetch-attempts')->assertSuccessful();
        $this->assertDatabaseCount('crawl_fetch_attempts', 2);
        $this->assertDatabaseMissing('crawl_fetch_attempts', ['error' => 'expired']);
    }

    public function test_report_filters_source_window_and_groups_outcomes(): void
    {
        $this->freezeTime();
        $this->insert(now(), 'challenge');
        $this->insert(now(), 'challenge');
        $this->insert(now()->subHours(25), 'old');
        $this->insert(now(), 'other', 'other');
        $this->artisan('crawler:fetch-report onejav --hours=24')
            ->expectsTable(['Method', 'Outcome', 'Count'], [['http', 'error', 2]])
            ->expectsTable(['Error', 'Count'], [['challenge', 2]])->assertSuccessful();
        $this->artisan('crawler:fetch-report empty')->expectsOutputToContain('No fetch attempts')->assertSuccessful();
        $this->artisan('crawler:fetch-report onejav --hours=invalid')->assertFailed();
    }

    public function test_report_limits_errors_to_five_with_deterministic_ties(): void
    {
        $this->freezeTime();
        foreach (['a', 'b', 'c', 'd', 'e', 'f'] as $error) {
            $this->insert(now(), $error);
        }
        $this->artisan('crawler:fetch-report onejav')
            ->expectsTable(['Method', 'Outcome', 'Count'], [['http', 'error', 6]])
            ->expectsTable(['Error', 'Count'], [['a', 1], ['b', 1], ['c', 1], ['d', 1], ['e', 1]])->assertSuccessful();
    }

    private function insert(\DateTimeInterface $createdAt, string $error, string $source = 'onejav'): void
    {
        DB::table('crawl_fetch_attempts')->insert(['created_at' => $createdAt, 'source_slug' => $source, 'url_hash' => str_repeat('a', 64), 'method' => 'http', 'started_at' => $createdAt, 'duration_ms' => 1, 'outcome' => 'error', 'error' => $error]);
    }
}
