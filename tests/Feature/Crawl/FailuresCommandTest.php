<?php

declare(strict_types=1);

namespace Tests\Feature\Crawl;

use App\Enums\CrawlErrorCode;
use App\Models\CrawlQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class FailuresCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_json_counts_recent_failures_by_source_and_error_code(): void
    {
        $this->freezeSecond();

        CrawlQueue::factory()->create([
            'source_slug' => 'onejav',
            'status' => CrawlQueue::STATUS_FAILED,
            'error_code' => CrawlErrorCode::Blocked->value,
            'last_error' => 'Blocked by site.',
            'last_failed_at' => now()->subHour(),
        ]);
        CrawlQueue::factory()->create([
            'source_slug' => 'onejav',
            'status' => CrawlQueue::STATUS_FAILED,
            'error_code' => CrawlErrorCode::Blocked->value,
            'last_error' => 'Blocked again.',
            'last_failed_at' => now()->subMinutes(10),
        ]);
        CrawlQueue::factory()->create([
            'source_slug' => 'javdb',
            'status' => CrawlQueue::STATUS_FAILED,
            'error_code' => CrawlErrorCode::Network->value,
            'last_error' => 'Network unavailable.',
            'last_failed_at' => now()->subMinutes(5),
        ]);
        CrawlQueue::factory()->create([
            'source_slug' => 'onejav',
            'status' => CrawlQueue::STATUS_FAILED,
            'error_code' => CrawlErrorCode::Timeout->value,
            'last_error' => 'Old failure.',
            'last_failed_at' => now()->subDays(2),
        ]);

        self::assertSame(0, Artisan::call('crawler:failures', ['--since' => '24h', '--json' => true]));
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(3, $report['total']);
        self::assertSame([
            ['source' => 'javdb', 'error_code' => 'network', 'count' => 1],
            ['source' => 'onejav', 'error_code' => 'blocked', 'count' => 2],
        ], $report['failures']);
    }

    public function test_source_filter_and_text_output_report_matching_failures(): void
    {
        CrawlQueue::factory()->create([
            'source_slug' => 'onejav',
            'status' => CrawlQueue::STATUS_FAILED,
            'error_code' => CrawlErrorCode::NotFound->value,
            'last_error' => 'Not found.',
            'last_failed_at' => now(),
        ]);
        CrawlQueue::factory()->create([
            'source_slug' => 'javdb',
            'status' => CrawlQueue::STATUS_FAILED,
            'error_code' => CrawlErrorCode::Network->value,
            'last_error' => 'Network unavailable.',
            'last_failed_at' => now(),
        ]);

        $this->artisan('crawler:failures', ['--since' => '24h', '--source' => 'onejav'])
            ->expectsOutputToContain('not_found')
            ->expectsOutputToContain('Total: 1')
            ->assertExitCode(0);
    }

    public function test_invalid_since_value_returns_a_clear_command_error(): void
    {
        $this->artisan('crawler:failures', ['--since' => 'not-a-duration-or-date'])
            ->expectsOutputToContain('Invalid --since value.')
            ->assertExitCode(1);
    }

    public function test_iso_since_with_no_matching_rows_reports_an_empty_period(): void
    {
        $this->artisan('crawler:failures', [
            '--since' => '2026-01-01T00:00:00+00:00',
            '--source' => 'missing-source',
        ])
            ->expectsOutputToContain('No crawl failures found for the selected period.')
            ->assertExitCode(0);
    }
}
