<?php

declare(strict_types=1);

namespace Tests\Feature\Crawl;

use App\Models\Source;
use App\Services\Crawler\CrawlerxClient;
use App\Services\Crawler\CrawlerxFetchResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class SourceHealthCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_json_reports_a_blocked_source_and_a_disabled_one_and_writes_the_report(): void
    {
        config([
            'javmeta_sources.sources' => [
                'demo' => ['movie_listing_urls' => ['https://example.test/']],
                'off' => ['movie_listing_urls' => ['https://off.test/']],
            ],
        ]);
        Source::query()->create(['slug' => 'demo', 'name' => 'Demo', 'base_url' => 'https://example.test', 'enabled' => true, 'priority' => 1, 'gap_seconds_default' => 10, 'gap_seconds_min' => 5, 'gap_seconds_max' => 45, 'gap_seconds_current' => 10]);
        Source::query()->create(['slug' => 'off', 'name' => 'Off', 'base_url' => 'https://off.test', 'enabled' => false, 'priority' => 2, 'gap_seconds_default' => 10, 'gap_seconds_min' => 5, 'gap_seconds_max' => 45, 'gap_seconds_current' => 10]);
        $this->app->instance(CrawlerxClient::class, new class extends CrawlerxClient {
            public function fetchListing(string $sourceSlug, string $url): CrawlerxFetchResult
            {
                return CrawlerxFetchResult::failure(CrawlerxClient::ERROR_BLOCKED, 'Blocked by the site');
            }
        });
        $directory = base_path('artifacts/reports');
        File::delete([$directory . '/source-health.json', $directory . '/source-health.md']);

        $exit = Artisan::call('crawler:source-health', ['--json' => true]);
        $output = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(0, $exit);
        self::assertSame(['WORKING' => 0, 'DEGRADED' => 0, 'BLOCKED' => 1, 'DISABLED' => 1], $output['summary']);
        self::assertSame('blocked', $output['rows'][0]['error_code']);
        self::assertFileExists($directory . '/source-health.json');
        self::assertStringContainsString('| demo | listing | BLOCKED |', (string) file_get_contents($directory . '/source-health.md'));
        File::delete([$directory . '/source-health.json', $directory . '/source-health.md']);
    }

    public function test_table_output_and_the_source_filter(): void
    {
        config(['javmeta_sources.sources' => [
            'one' => ['movie_listing_urls' => ['https://one.test/']],
            'two' => ['movie_listing_urls' => ['https://two.test/']],
        ]]);
        Source::query()->create(['slug' => 'one', 'name' => 'One', 'base_url' => 'https://one.test', 'enabled' => true, 'priority' => 1, 'gap_seconds_default' => 10, 'gap_seconds_min' => 5, 'gap_seconds_max' => 45, 'gap_seconds_current' => 10]);
        $this->app->instance(CrawlerxClient::class, new class extends CrawlerxClient {
            public function fetchListing(string $sourceSlug, string $url): CrawlerxFetchResult
            {
                return CrawlerxFetchResult::failure(CrawlerxClient::ERROR_NETWORK, 'No route');
            }
        });

        $this->artisan('crawler:source-health', ['--source' => 'one', '--no-write' => true])
            ->expectsOutputToContain('WORKING 0, DEGRADED 0, BLOCKED 1, DISABLED 0')
            ->assertSuccessful();
    }
}
