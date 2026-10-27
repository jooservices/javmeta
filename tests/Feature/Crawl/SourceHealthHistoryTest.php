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
        config(['javmeta_sources.sources' => [
            'demo' => ['movie_listing_urls' => ['https://example.test/']],
            'other' => ['movie_listing_urls' => ['https://other.example.test/']],
        ]]);
        Source::query()->create(['slug' => 'demo', 'name' => 'Demo', 'base_url' => 'https://example.test', 'enabled' => true, 'priority' => 1, 'gap_seconds_default' => 10, 'gap_seconds_min' => 5, 'gap_seconds_max' => 45, 'gap_seconds_current' => 10]);
        Source::query()->create(['slug' => 'other', 'name' => 'Other', 'base_url' => 'https://other.example.test', 'enabled' => true, 'priority' => 2, 'gap_seconds_default' => 10, 'gap_seconds_min' => 5, 'gap_seconds_max' => 45, 'gap_seconds_current' => 10]);
        $history = storage_path('app/private/reports/source-health-history.jsonl');
        File::delete($history);

        $sent = [];
        $this->app->instance(ReportChannel::class, new class ($sent) implements ReportChannel {
            /** @param list<array{subject: string, body: string, context: array<string, mixed>}> $sent */
            public function __construct(public array &$sent) {}

            public function deliver(string $subject, string $body, array $context = []): bool
            {
                $this->sent[] = ['subject' => $subject, 'body' => $body, 'context' => $context];

                return true;
            }
        });
        $this->app->instance(CrawlerxClient::class, new class extends CrawlerxClient {
            /** @var list<string> */
            public static array $blockedSources = [];

            public function fetchListing(string $sourceSlug, string $url): CrawlerxFetchResult
            {
                return in_array($sourceSlug, self::$blockedSources, true)
                    ? CrawlerxFetchResult::failure(CrawlerxClient::ERROR_BLOCKED, 'Blocked by the site')
                    : CrawlerxFetchResult::failure(CrawlerxClient::ERROR_PARSE_FAILED, 'x');
            }
        });
        $client = $this->app->make(CrawlerxClient::class);

        $client::$blockedSources = [];
        Artisan::call('crawler:source-health', ['--delay' => 1]);
        $this->assertSame([], $sent, 'the first check is only a baseline');

        $client::$blockedSources = ['demo'];
        Artisan::call('crawler:source-health', ['--delay' => 1]);
        $this->assertCount(1, $sent);
        $this->assertSame('open', $sent[0]['context']['alert_state']);
        $this->assertSame('warning', $sent[0]['context']['severity']);
        $this->assertStringContainsString('demo', $sent[0]['body']);

        Artisan::call('crawler:source-health', ['--source' => 'other', '--delay' => 1]);
        $this->assertCount(1, $sent, 'a filtered check does not resolve omitted sources');
        $this->assertCount(2, array_filter(explode("\n", (string) file_get_contents($history))), 'a filtered check does not replace the full history baseline');

        Artisan::call('crawler:source-health', ['--delay' => 1]);
        $this->assertCount(1, $sent, 'a source that stays blocked does not alert again');

        $client::$blockedSources = ['other'];
        Artisan::call('crawler:source-health', ['--delay' => 1]);
        $this->assertCount(3, $sent, 'new blocks and recoveries are sent separately');
        $this->assertSame('open', $sent[1]['context']['alert_state']);
        $this->assertSame('warning', $sent[1]['context']['severity']);
        $this->assertStringContainsString('other', $sent[1]['body']);
        $this->assertSame('resolved', $sent[2]['context']['alert_state']);
        $this->assertSame('info', $sent[2]['context']['severity']);
        $this->assertStringContainsString('Recovered: demo', $sent[2]['body']);
        $this->assertCount(4, array_filter(explode("\n", (string) file_get_contents($history))));
        File::delete($history);
    }
}
