<?php

declare(strict_types=1);

namespace Tests\Feature\Crawl;

use App\Contracts\ReportChannel;
use App\Models\Source;
use App\Services\Crawler\CrawlerxClient;
use App\Services\Crawler\CrawlerxFetchResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class SourceHealthHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_history_grows_and_source_block_and_recovery_transitions_alert(): void
    {
        config(['javmeta_sources.sources' => ['demo' => ['movie_listing_urls' => ['https://example.test/']]]]);
        Source::query()->create(['slug' => 'demo', 'name' => 'Demo', 'base_url' => 'https://example.test', 'enabled' => true, 'priority' => 1, 'gap_seconds_default' => 10, 'gap_seconds_min' => 5, 'gap_seconds_max' => 45, 'gap_seconds_current' => 10]);
        $history = storage_path('app/private/reports/source-health-history.jsonl');
        File::delete($history);

        $sent = [];
        $this->app->instance(ReportChannel::class, new class ($sent) implements ReportChannel {
            /** @param list<string> $sent */
            public function __construct(public array &$sent) {}

            public function deliver(string $subject, string $body, array $context = []): bool
            {
                $this->sent[] = $body;

                return true;
            }
        });
        $this->app->instance(CrawlerxClient::class, new class extends CrawlerxClient {
            public static bool $blocked = false;

            public function fetchListing(string $sourceSlug, string $url): CrawlerxFetchResult
            {
                return self::$blocked ? CrawlerxFetchResult::failure(CrawlerxClient::ERROR_BLOCKED, 'Blocked by the site') : CrawlerxFetchResult::failure(CrawlerxClient::ERROR_PARSE_FAILED, 'x');
            }
        });
        $client = $this->app->make(CrawlerxClient::class);

        $client::$blocked = false;
        Artisan::call('crawler:source-health', ['--delay' => 1]);
        $this->assertSame([], $sent, 'the first check is only a baseline');

        $client::$blocked = true;
        Artisan::call('crawler:source-health', ['--delay' => 1]);
        $this->assertCount(1, $sent);
        $this->assertStringContainsString('demo', $sent[0]);

        Artisan::call('crawler:source-health', ['--delay' => 1]);
        $this->assertCount(1, $sent, 'a source that stays blocked does not alert again');

        $client::$blocked = false;
        Artisan::call('crawler:source-health', ['--delay' => 1]);
        $this->assertCount(2, $sent);
        $this->assertStringContainsString('Recovered: demo', $sent[1]);
        $this->assertCount(4, array_filter(explode("\n", (string) file_get_contents($history))));
        File::delete($history);
    }
}
