<?php

declare(strict_types=1);

namespace Tests\Feature\Crawl;

use App\Enums\CrawlErrorCode;
use App\Models\CrawlQueue;
use App\Models\Source;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Events\QueryExecuted;
use Tests\TestCase;

final class RetryCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_retry_commands_are_registered_and_list_output_redacts_url_query_data(): void
    {
        $row = CrawlQueue::factory()->create([
            'status' => CrawlQueue::STATUS_RETRY_PENDING,
            'retry_round' => 2,
            'error_code' => CrawlErrorCode::Network->value,
            'url' => 'https://retry.test/path?token=must-not-display',
        ]);

        foreach (['crawler:retry-list', 'crawler:retry-requeue', 'crawler:retry-import', 'crawler:retry-stats'] as $command) {
            self::assertArrayHasKey($command, app(\Illuminate\Contracts\Console\Kernel::class)->all());
        }

        $this->artisan('crawler:retry-list', ['--source' => $row->source_slug, '--round' => 2])
            ->expectsOutputToContain('retry.test/path')
            ->assertSuccessful();
        Artisan::call('crawler:retry-list');
        self::assertStringNotContainsString('must-not-display', Artisan::output());
    }

    public function test_retry_stats_are_readable_by_source_and_include_egress_outcomes(): void
    {
        $source = Source::factory()->create(['slug' => 'retry-stats']);
        CrawlQueue::factory()->create([
            'source_slug' => $source->slug,
            'status' => CrawlQueue::STATUS_RETRY_PENDING,
            'retry_round' => 2,
            'retry_egress_label' => 'clean-egress',
        ]);
        DB::table('crawl_retry_metrics')->insert([
            ['source_slug' => $source->slug, 'round' => 2, 'outcome' => 'success', 'egress_label' => 'clean-egress', 'value' => 4],
            ['source_slug' => $source->slug, 'round' => 2, 'outcome' => 'failed_permanent', 'egress_label' => 'clean-egress', 'value' => 1],
        ]);

        self::assertSame(0, Artisan::call('crawler:retry-stats', ['--source' => $source->slug]));
        $output = Artisan::output();
        self::assertStringContainsString('retry-stats', $output);
        self::assertStringContainsString('clean-egress', $output);
        self::assertStringContainsString('4', $output);
        self::assertStringContainsString('Failed permanently', $output);
    }

    public function test_import_dry_run_previews_only_old_retryable_rows_and_requeue_uses_round_one(): void
    {
        $source = Source::factory()->create(['slug' => 'retry-ops']);
        $old = CrawlQueue::factory()->create([
            'source_slug' => $source->slug, 'status' => CrawlQueue::STATUS_FAILED,
            'error_code' => CrawlErrorCode::Timeout->value, 'last_failed_at' => now()->subHours(2),
        ]);
        $recent = CrawlQueue::factory()->create([
            'source_slug' => $source->slug, 'status' => CrawlQueue::STATUS_FAILED,
            'error_code' => CrawlErrorCode::Timeout->value, 'last_failed_at' => now()->subMinutes(10),
        ]);

        $this->artisan('crawler:retry-import', ['--dry-run' => true, '--source' => $source->slug])
            ->expectsOutputToContain('Eligible historical failures: 1.')
            ->assertSuccessful();
        self::assertSame(CrawlQueue::STATUS_FAILED, $old->refresh()->status);

        $this->artisan('crawler:retry-import', ['--source' => $source->slug])
            ->expectsOutputToContain('Imported historical failures: 1.')
            ->assertSuccessful();
        self::assertSame(CrawlQueue::STATUS_RETRY_PENDING, $old->refresh()->status);
        self::assertSame(CrawlQueue::STATUS_FAILED, $recent->refresh()->status);

        $permanent = CrawlQueue::factory()->create([
            'source_slug' => $source->slug, 'status' => CrawlQueue::STATUS_FAILED,
            'error_code' => CrawlErrorCode::WorkerLost->value, 'last_error' => 'still transient',
        ]);
        $this->artisan('crawler:retry-requeue', ['id' => $permanent->id])->assertSuccessful();
        self::assertSame(CrawlQueue::STATUS_RETRY_PENDING, $permanent->refresh()->status);
        self::assertSame(1, $permanent->retry_round);

        $terminal = CrawlQueue::factory()->create([
            'source_slug' => $source->slug, 'status' => CrawlQueue::STATUS_FAILED,
            'error_code' => CrawlErrorCode::Gone->value,
        ]);
        $this->artisan('crawler:retry-requeue', ['id' => $terminal->id])->assertFailed();
        self::assertSame(CrawlQueue::STATUS_FAILED, $terminal->refresh()->status);
    }

    public function test_retry_requeue_rejects_conflicting_or_missing_arguments_and_requeues_source_batch(): void
    {
        $source = Source::factory()->create(['slug' => 'retry-batch']);
        $retryable = CrawlQueue::factory()->create([
            'source_slug' => $source->slug,
            'status' => CrawlQueue::STATUS_FAILED,
            'error_code' => CrawlErrorCode::Timeout->value,
        ]);

        $this->artisan('crawler:retry-requeue', ['id' => $retryable->id, '--source' => $source->slug])
            ->expectsOutputToContain('Use either an ID or --source.')
            ->assertFailed();
        $this->artisan('crawler:retry-requeue')
            ->expectsOutputToContain('Provide a row ID or --source.')
            ->assertFailed();
        $this->artisan('crawler:retry-requeue', ['id' => 'not-a-number'])
            ->expectsOutputToContain('Provide a row ID or --source.')
            ->assertFailed();
        $this->artisan('crawler:retry-requeue', ['id' => 999999])
            ->expectsOutputToContain('The row is missing or its error is not retryable.')
            ->assertFailed();
        $this->artisan('crawler:retry-requeue', ['--source' => $source->slug])
            ->expectsOutputToContain("Requeued 1 retryable failure(s) for {$source->slug}.")
            ->assertSuccessful();

        self::assertSame(CrawlQueue::STATUS_RETRY_PENDING, $retryable->refresh()->status);
    }

    public function test_ssrf_requeue_dry_run_is_source_filtered_and_does_not_write(): void
    {
        $source = Source::factory()->create(['slug' => 'ssrf-preview']);
        $otherSource = Source::factory()->create(['slug' => 'ssrf-other']);
        $eligible = CrawlQueue::factory()->create([
            'source_slug' => $source->slug,
            'status' => CrawlQueue::STATUS_FAILED,
            'error_code' => CrawlErrorCode::SsrfBlocked->value,
        ]);
        $otherError = CrawlQueue::factory()->create([
            'source_slug' => $source->slug,
            'status' => CrawlQueue::STATUS_FAILED,
            'error_code' => CrawlErrorCode::Timeout->value,
        ]);
        $otherSourceFailure = CrawlQueue::factory()->create([
            'source_slug' => $otherSource->slug,
            'status' => CrawlQueue::STATUS_FAILED,
            'error_code' => CrawlErrorCode::SsrfBlocked->value,
        ]);
        $inFlight = CrawlQueue::factory()->create([
            'source_slug' => $source->slug,
            'status' => CrawlQueue::STATUS_CLAIMED,
            'error_code' => CrawlErrorCode::SsrfBlocked->value,
        ]);

        $this->artisan('crawler:retry-requeue', [
            '--ssrf-blocked' => true,
            '--source' => $source->slug,
            '--batch-size' => 1,
        ])
            ->expectsOutputToContain('Eligible SSRF-blocked failed rows: 1.')
            ->expectsOutputToContain('Dry run only; no rows changed (batch limit: 1).')
            ->assertSuccessful();

        self::assertSame(CrawlQueue::STATUS_FAILED, $eligible->refresh()->status);
        self::assertSame(CrawlQueue::STATUS_FAILED, $otherError->refresh()->status);
        self::assertSame(CrawlQueue::STATUS_FAILED, $otherSourceFailure->refresh()->status);
        self::assertSame(CrawlQueue::STATUS_CLAIMED, $inFlight->refresh()->status);
    }

    public function test_ssrf_requeue_applies_a_deterministic_bounded_batch_and_preserves_diagnostics(): void
    {
        $source = Source::factory()->create(['slug' => 'ssrf-batch']);
        $firstFailureAt = now()->subMinutes(5)->startOfSecond();
        $first = CrawlQueue::factory()->create([
            'source_slug' => $source->slug,
            'status' => CrawlQueue::STATUS_FAILED,
            'error_code' => CrawlErrorCode::SsrfBlocked->value,
            'last_error' => 'Fetch blocked by the SSRF guard.',
            'last_failed_at' => $firstFailureAt,
            'attempts' => 4,
            'retry_round' => 3,
        ]);
        $second = CrawlQueue::factory()->create([
            'source_slug' => $source->slug,
            'status' => CrawlQueue::STATUS_FAILED,
            'error_code' => CrawlErrorCode::SsrfBlocked->value,
        ]);
        $otherFailure = CrawlQueue::factory()->create([
            'source_slug' => $source->slug,
            'status' => CrawlQueue::STATUS_FAILED,
            'error_code' => CrawlErrorCode::Gone->value,
        ]);

        $this->artisan('crawler:retry-requeue', [
            '--ssrf-blocked' => true,
            '--source' => $source->slug,
            '--batch-size' => 1,
            '--apply' => true,
        ])
            ->expectsOutputToContain('Eligible SSRF-blocked failed rows: 2.')
            ->expectsOutputToContain('Requeued SSRF-blocked failed rows: 1 (batch limit: 1).')
            ->assertSuccessful();

        $first->refresh();
        $second->refresh();
        $otherFailure->refresh();
        self::assertSame(CrawlQueue::STATUS_RETRY_PENDING, $first->status);
        self::assertSame(0, $first->attempts);
        self::assertSame(1, $first->retry_round);
        self::assertSame(CrawlErrorCode::SsrfBlocked->value, $first->error_code);
        self::assertSame('Fetch blocked by the SSRF guard.', $first->last_error);
        self::assertTrue($first->last_failed_at->equalTo($firstFailureAt));
        self::assertSame(CrawlQueue::STATUS_FAILED, $second->status);
        self::assertSame(CrawlQueue::STATUS_FAILED, $otherFailure->status);

        $this->artisan('crawler:retry-requeue', [
            '--ssrf-blocked' => true,
            '--source' => $source->slug,
            '--batch-size' => 1,
            '--apply' => true,
        ])
            ->expectsOutputToContain('Eligible SSRF-blocked failed rows: 1.')
            ->expectsOutputToContain('Requeued SSRF-blocked failed rows: 1 (batch limit: 1).')
            ->assertSuccessful();

        $this->artisan('crawler:retry-requeue', [
            '--ssrf-blocked' => true,
            '--source' => $source->slug,
            '--batch-size' => 1,
            '--apply' => true,
        ])
            ->expectsOutputToContain('Eligible SSRF-blocked failed rows: 0.')
            ->expectsOutputToContain('Requeued SSRF-blocked failed rows: 0 (batch limit: 1).')
            ->assertSuccessful();
    }

    public function test_ssrf_requeue_rejects_invalid_batch_size_and_source_before_writes(): void
    {
        $source = Source::factory()->create(['slug' => 'ssrf-invalid']);
        $failure = CrawlQueue::factory()->create([
            'source_slug' => $source->slug,
            'status' => CrawlQueue::STATUS_FAILED,
            'error_code' => CrawlErrorCode::SsrfBlocked->value,
        ]);

        $this->artisan('crawler:retry-requeue', [
            '--ssrf-blocked' => true,
            '--source' => $source->slug,
            '--batch-size' => 0,
            '--apply' => true,
        ])
            ->expectsOutputToContain('Batch size must be between 1 and 500.')
            ->assertFailed();
        self::assertSame(CrawlQueue::STATUS_FAILED, $failure->refresh()->status);

        $this->artisan('crawler:retry-requeue', [
            '--ssrf-blocked' => true,
            '--source' => 'missing-source',
            '--apply' => true,
        ])
            ->expectsOutputToContain('The source filter is invalid.')
            ->assertFailed();
        self::assertSame(CrawlQueue::STATUS_FAILED, $failure->refresh()->status);
    }

    public function test_ssrf_requeue_does_not_overwrite_a_row_changed_after_selection(): void
    {
        $source = Source::factory()->create(['slug' => 'ssrf-concurrent']);
        $failure = CrawlQueue::factory()->create([
            'source_slug' => $source->slug,
            'status' => CrawlQueue::STATUS_FAILED,
            'error_code' => CrawlErrorCode::SsrfBlocked->value,
        ]);
        $changed = false;
        DB::listen(static function (QueryExecuted $query) use (&$changed, $failure): void {
            $sql = strtolower(str_replace(['"', '`'], '', $query->sql));
            if (! $changed && preg_match('/^select\s+id\s+from\s+crawl_queue\b/', $sql) === 1) {
                $changed = true;
                DB::table('crawl_queue')->where('id', $failure->id)->update([
                    'status' => CrawlQueue::STATUS_CLAIMED,
                    'locked_by' => 'concurrent-worker',
                ]);
            }
        });

        $this->artisan('crawler:retry-requeue', [
            '--ssrf-blocked' => true,
            '--source' => $source->slug,
            '--apply' => true,
        ])
            ->expectsOutputToContain('Eligible SSRF-blocked failed rows: 1.')
            ->expectsOutputToContain('Requeued SSRF-blocked failed rows: 0 (batch limit: 100).')
            ->assertSuccessful();

        self::assertTrue($changed);
        self::assertSame(CrawlQueue::STATUS_CLAIMED, $failure->refresh()->status);
        self::assertSame('concurrent-worker', $failure->locked_by);
    }
}
