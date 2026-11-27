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
        $this->travelTo('2026-11-02T12:34:00Z');
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
        $this->travelTo('2026-11-02T12:34:00Z');
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
        $this->travelTo('2026-11-02T12:34:00Z');
        foreach (['a', 'b', 'c', 'd', 'e', 'f'] as $error) {
            $this->insert(now(), $error);
        }
        $this->artisan('crawler:fetch-report onejav')
            ->expectsTable(['Method', 'Outcome', 'Count'], [['http', 'error', 6]])
            ->expectsTable(['Error', 'Count'], [['a', 1], ['b', 1], ['c', 1], ['d', 1], ['e', 1]])->assertSuccessful();
    }

    public function test_failure_classes_gives_every_source_one_verdict_with_its_evidence(): void
    {
        $this->travelTo('2026-11-02T12:34:00Z');
        $this->insertFailures('venus', 'http', null, 'fetch_error', 'network_timeout', 5);
        $this->insertFailures('venus', 'playwright', null, 'fetch_error', 'network_timeout', 1);
        $this->insertFailures('onejav', 'http', 403, 'challenge', 'challenge detected', 3);
        $this->insertFailures('onejav', 'http', 404, 'http_404', 'HTTP 404', 1);
        $this->insertFailures('gone', 'http', 404, 'http_404', 'HTTP 404', 2);
        $this->insertFailures('old', 'http', 403, 'challenge', 'challenge detected', 4, now()->subHours(100));
        $this->artisan('crawler:failure-classes --hours=72')
            ->expectsTable(
                ['Source', 'Errors', 'Verdict', 'Method', 'HTTP status', 'Error code'],
                [
                    ['venus', 6, 'own_fault', 'http', '-', 'fetch_error'],
                    ['onejav', 4, 'real_blocker', 'http', 403, 'challenge'],
                    ['gone', 2, 'expected_outcome', 'http', 404, 'http_404'],
                ],
            )->assertSuccessful();
    }

    public function test_failure_classes_reports_an_empty_window_and_rejects_a_bad_window(): void
    {
        $this->artisan('crawler:failure-classes')->expectsOutputToContain('No failed fetch attempts')->assertSuccessful();
        $this->artisan('crawler:failure-classes --hours=invalid')->assertFailed();
    }

    private function insertFailures(string $source, string $method, ?int $status, string $code, string $error, int $count, ?\DateTimeInterface $at = null): void
    {
        for ($i = 0; $i < $count; $i++) {
            DB::table('crawl_fetch_attempts')->insert(['created_at' => $at ?? now(), 'source_slug' => $source, 'url_hash' => str_repeat('a', 64), 'method' => $method, 'started_at' => $at ?? now(), 'duration_ms' => 1, 'http_status' => $status, 'outcome' => 'error', 'error_code' => $code, 'error' => $error]);
        }
    }

    private function insert(\DateTimeInterface $createdAt, string $error, string $source = 'onejav'): void
    {
        DB::table('crawl_fetch_attempts')->insert(['created_at' => $createdAt, 'source_slug' => $source, 'url_hash' => str_repeat('a', 64), 'method' => 'http', 'started_at' => $createdAt, 'duration_ms' => 1, 'outcome' => 'error', 'error' => $error]);
    }
}
