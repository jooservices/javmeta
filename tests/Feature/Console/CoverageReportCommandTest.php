<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\CrawlQueue;
use App\Models\Source;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class CoverageReportCommandTest extends TestCase
{
    use RefreshDatabase;

    private const HEADERS = ['Source', 'Listing pages found', 'Listing pages crawled', 'Detail pending', 'Detail done', 'Last new item', 'Verdict'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-28 12:00:00'));
        config()->set('javmeta_queues.coverage_exhausted_after_days', 7);
    }

    public function test_the_report_prints_the_figures_and_the_verdict_of_every_enabled_source(): void
    {
        $this->source('alpha', listingDone: 4, detailDone: 10, detailPending: 2, newItemHoursAgo: 5);
        $this->source('beta', listingDone: 4, detailDone: 10, detailPending: 0, newItemHoursAgo: 24 * 9);
        $this->source('gamma', listingDone: 0, detailDone: 0, detailPending: 0, newItemHoursAgo: null);
        Source::factory()->create(['slug' => 'off', 'enabled' => false]);

        $this->artisan('crawler:coverage-report')
            ->expectsTable(self::HEADERS, [
                ['alpha', 4, 4, 2, 10, '5 h ago', 'not_fully_crawled'],
                ['beta', 4, 4, 0, 10, '216 h ago', 'exhausted'],
                ['gamma', 0, 0, 0, 0, 'none in the statistics', 'unknown'],
            ])
            ->assertSuccessful();
    }

    public function test_one_source_can_be_asked_for_and_an_unknown_one_is_refused(): void
    {
        $this->source('alpha', listingDone: 1, detailDone: 1, detailPending: 0, newItemHoursAgo: 1);
        $this->source('beta', listingDone: 1, detailDone: 1, detailPending: 0, newItemHoursAgo: 1);

        $this->artisan('crawler:coverage-report --source=beta')
            ->expectsTable(self::HEADERS, [['beta', 1, 1, 0, 1, '1 h ago', 'not_fully_crawled']])
            ->assertSuccessful();
        $this->artisan('crawler:coverage-report --source=nope')->expectsOutputToContain('Unknown or disabled source [nope].')->assertFailed();
    }

    private function source(string $slug, int $listingDone, int $detailDone, int $detailPending, ?int $newItemHoursAgo): void
    {
        Source::factory()->create(['slug' => $slug, 'enabled' => true]);
        foreach ([['listing', 'done', $listingDone], ['detail', 'done', $detailDone], ['detail', 'pending', $detailPending]] as [$kind, $status, $count]) {
            for ($index = 0; $index < $count; $index++) {
                CrawlQueue::query()->create(['source_slug' => $slug, 'url' => 'https://example.test/' . $slug . '/' . $kind . $status . $index, 'kind' => $kind, 'status' => $status, 'attempts' => 0, 'max_attempts' => 3]);
            }
        }
        if ($newItemHoursAgo !== null) {
            DB::table('crawl_stats_hourly')->insert([
                'hour_at' => now()->startOfHour()->subHours($newItemHoursAgo), 'source_slug' => $slug, 'lane' => 'direct', 'method' => 'http', 'attempts' => 1, 'ok' => 1,
                'movies_new' => 2, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }
}
