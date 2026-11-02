<?php

declare(strict_types=1);

namespace Tests\Feature\Crawl;

use App\Enums\CrawlErrorCode;
use App\Models\CrawlQueue;
use App\Models\Source;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
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
}
