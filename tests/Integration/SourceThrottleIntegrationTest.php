<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Jobs\FetchListingJob;
use App\Models\CrawlQueue;
use App\Models\Source;
use App\Services\Crawl\SourceThrottle;
use App\Services\Crawler\CrawlerxClient;
use App\Services\Crawler\CrawlerxFetchResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use JOOservices\CrawlerX\Dto\CrawlListResultDto;
use JOOservices\CrawlerX\Dto\CrawlPaginationDto;
use Tests\Support\FakeCrawlerxClient;

final class SourceThrottleIntegrationTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.default' => 'array']);
        Cache::setDefaultDriver('array');
        Cache::store('array')->flush();
    }

    public function test_successful_listing_jobs_record_and_apply_sustained_slow_samples(): void
    {
        self::assertSame('pgsql', DB::getDriverName());
        $this->configureLatencyGap(['sample_window_size' => 3, 'sustained_samples' => 2]);
        $this->configureMetrics();
        Http::fake(['*' => Http::response([], 200)]);

        $source = $this->source(0.5, 0.5, 15.0);
        $previousGap = (float) $source->gap_seconds_current;
        for ($index = 0; $index < 5; $index++) {
            $row = $this->listingRow($source);
            $this->runListingJob($row, $this->successfulResult([$this->attempt(3000)]));
            $source = Source::query()->findOrFail($source->getKey());

            if ($index < 2) {
                self::assertSame($previousGap, (float) $source->gap_seconds_current);
            } else {
                self::assertGreaterThan($previousGap, (float) $source->gap_seconds_current);
            }
            $previousGap = (float) $source->gap_seconds_current;
        }

        self::assertGreaterThan(0.5, (float) $source->gap_seconds_current);
        self::assertSame(5, DB::table('crawl_fetch_attempts')->where('source_slug', $source->slug)->count());
        self::assertSame(CrawlQueue::STATUS_DONE, DB::table('crawl_queue')->where('source_slug', $source->slug)->latest('id')->value('status'));
        Http::assertSentCount(3);
        Http::assertSent(static fn(\Illuminate\Http\Client\Request $request): bool => data_get(
            $request->data(),
            'resourceMetrics.0.scopeMetrics.0.metrics.0.name',
        ) === 'javmeta_source_gap_changes_total');
    }

    public function test_successful_listing_jobs_widen_above_a_higher_minimum_and_recover_gradually(): void
    {
        self::assertSame('pgsql', DB::getDriverName());
        $this->configureLatencyGap(['sample_window_size' => 3, 'sustained_samples' => 2]);
        $this->configureMetrics();
        Http::fake(['*' => Http::response([], 200)]);

        $source = $this->source(10.0, 2.0, 60.0);
        for ($index = 0; $index < 4; $index++) {
            $this->runListingJob(
                $this->listingRow($source),
                $this->successfulResult([$this->attempt(3000)]),
            );
        }

        $afterSlow = (float) Source::query()->findOrFail($source->getKey())->gap_seconds_current;
        self::assertGreaterThan(10.0, $afterSlow);

        // Healthy fetches only start to lower the gap once the slow samples have left the three-sample window,
        // so let the window clear first and assert recovery from there.
        for ($index = 0; $index < 3; $index++) {
            $this->runListingJob(
                $this->listingRow($source),
                $this->successfulResult([$this->attempt(100)]),
            );
        }
        $afterWindowCleared = (float) Source::query()->findOrFail($source->getKey())->gap_seconds_current;

        $this->runListingJob(
            $this->listingRow($source),
            $this->successfulResult([$this->attempt(100)]),
        );
        $afterFirstRecovery = (float) Source::query()->findOrFail($source->getKey())->gap_seconds_current;

        $this->runListingJob(
            $this->listingRow($source),
            $this->successfulResult([$this->attempt(100)]),
        );
        $afterSecondRecovery = (float) Source::query()->findOrFail($source->getKey())->gap_seconds_current;

        self::assertLessThan($afterSlow, $afterWindowCleared);
        self::assertLessThanOrEqual($afterWindowCleared, $afterFirstRecovery);
        self::assertLessThanOrEqual($afterFirstRecovery, $afterSecondRecovery);
        self::assertLessThan($afterWindowCleared, $afterSecondRecovery);
        self::assertGreaterThanOrEqual(2.0, $afterSecondRecovery);
        Http::assertSent(static fn(\Illuminate\Http\Client\Request $request): bool => data_get(
            $request->data(),
            'resourceMetrics.0.scopeMetrics.0.metrics.0.gauge.dataPoints.0.attributes.0.value.stringValue',
        ) === 'latency_healthy');
    }

    public function test_successful_slow_fetches_accumulate_from_a_higher_effective_minimum(): void
    {
        self::assertSame('pgsql', DB::getDriverName());
        $this->configureLatencyGap(['sample_window_size' => 3, 'sustained_samples' => 2]);
        $this->configureMetrics();
        Http::fake(['*' => Http::response([], 200)]);

        $source = $this->source(2.0, 2.0, 60.0);
        $previousGap = 2.0;
        for ($index = 0; $index < 5; $index++) {
            $this->runListingJob(
                $this->listingRow($source),
                $this->successfulResult([$this->attempt(3000)]),
            );
            $currentGap = (float) Source::query()->findOrFail($source->getKey())->gap_seconds_current;

            if ($index < 2) {
                self::assertSame(2.0, $currentGap);
            } else {
                self::assertGreaterThan($previousGap, $currentGap);
            }
            $previousGap = $currentGap;
        }

        self::assertGreaterThan(2.0, $previousGap);
        self::assertLessThanOrEqual(60.0, $previousGap);
        Http::assertSentCount(3);
    }

    public function test_interleaved_workers_serialize_persisted_gap_changes_and_match_each_fetch_id(): void
    {
        self::assertSame('pgsql', DB::getDriverName());
        $this->configureLatencyGap(['sample_window_size' => 3, 'sustained_samples' => 2]);
        $this->configureMetrics();
        Http::fake(['*' => Http::response([], 200)]);

        $source = $this->source(10.0, 2.0, 60.0);
        $workerA = Source::query()->findOrFail($source->getKey());
        $workerB = Source::query()->findOrFail($source->getKey());
        $throttle = new SourceThrottle();
        $sampleA = $throttle->recordLatencySamples($source->slug, [2500]);
        $sampleB = $throttle->recordLatencySamples($source->slug, [3000]);
        $sampleC = $throttle->recordLatencySamples($source->slug, [4000]);
        $invalidSample = $throttle->recordLatencySamples($source->slug, [-1]);

        self::assertNotNull($sampleA);
        self::assertNotNull($sampleB);
        self::assertNotNull($sampleC);
        self::assertNull($invalidSample);

        $afterMissing = $throttle->onSuccess($workerB, $invalidSample);
        $afterB = $throttle->onSuccess($workerB, $sampleB);
        $afterA = $throttle->onSuccess($workerA, $sampleA);
        $afterC = $throttle->onSuccess($afterB, $sampleC);
        $persistedGap = (float) Source::query()->findOrFail($source->getKey())->gap_seconds_current;

        self::assertSame(9.2, (float) $afterMissing->gap_seconds_current);
        self::assertGreaterThan((float) $afterMissing->gap_seconds_current, (float) $afterB->gap_seconds_current);
        self::assertGreaterThan((float) $afterB->gap_seconds_current, (float) $afterA->gap_seconds_current);
        self::assertGreaterThan((float) $afterA->gap_seconds_current, (float) $afterC->gap_seconds_current);
        self::assertSame((float) $afterC->gap_seconds_current, $persistedGap);
        Http::assertSentCount(3);
    }

    public function test_missing_and_invalid_attempts_do_not_trigger_or_consume_latency_adjustments(): void
    {
        self::assertSame('pgsql', DB::getDriverName());
        $this->configureLatencyGap(['sample_window_size' => 3, 'sustained_samples' => 2]);
        $this->configureMetrics();
        Http::fake(['*' => Http::response([], 200)]);

        $source = $this->source(20.0, 2.0, 60.0);
        $this->runListingJob(
            $this->listingRow($source),
            $this->successfulResult([$this->attempt(-1)]),
        );
        $this->runListingJob($this->listingRow($source), $this->successfulResult([]));

        $source = Source::query()->findOrFail($source->getKey());
        self::assertSame(16.58, (float) $source->gap_seconds_current);
        self::assertSame(1, DB::table('crawl_fetch_attempts')->where('source_slug', $source->slug)->count());
        Http::assertNothingSent();
    }

    public function test_telemetry_failure_does_not_fail_or_undo_a_successful_fetch(): void
    {
        self::assertSame('pgsql', DB::getDriverName());
        $this->configureLatencyGap(['sample_window_size' => 3, 'sustained_samples' => 2]);
        $this->configureMetrics();
        Http::fake(static fn(): never => throw new ConnectionException('telemetry unavailable'));

        $source = $this->source(2.0, 2.0, 60.0);
        for ($index = 0; $index < 3; $index++) {
            $row = $this->listingRow($source);
            $this->runListingJob($row, $this->successfulResult([$this->attempt(3000)]));
        }

        self::assertSame(2.3, (float) Source::query()->findOrFail($source->getKey())->gap_seconds_current);
        self::assertSame(CrawlQueue::STATUS_DONE, $row->fresh()->status);
        Http::assertSentCount(1);
    }

    /** @param array<string, mixed> $overrides */
    private function configureLatencyGap(array $overrides): void
    {
        config()->set('javmeta_queues.latency_gap', array_merge([
            'enabled' => true,
            'sample_window_size' => 3,
            'sustained_samples' => 2,
            'slow_threshold_ms' => 2000,
            'healthy_threshold_ms' => 1000,
            'increase_percent' => 15,
            'recovery_percent' => 5,
            'window_ttl_seconds' => 3600,
        ], $overrides));
    }

    private function configureMetrics(): void
    {
        config([
            'openobserve.enabled' => true,
            'openobserve.url' => 'https://openobserve.test',
            'openobserve.org' => 'test',
            'openobserve.service_name' => 'javmeta-test',
            'app.env' => 'testing',
        ]);
    }

    private function source(float $current, float $minimum, float $maximum): Source
    {
        $slug = 'latency_' . Str::lower(Str::random(8));

        return Source::factory()->create([
            'slug' => $slug,
            'name' => $slug,
            'base_url' => 'https://latency.test',
            'gap_seconds_default' => $current,
            'gap_seconds_min' => $minimum,
            'gap_seconds_max' => $maximum,
            'gap_seconds_current' => $current,
        ]);
    }

    private function listingRow(Source $source): CrawlQueue
    {
        return CrawlQueue::factory()->create([
            'source_slug' => $source->slug,
            'url' => 'https://latency.test/list',
            'kind' => CrawlQueue::KIND_LISTING,
            'status' => CrawlQueue::STATUS_CLAIMED,
            'claimed_at' => now(),
            'attempts' => 0,
            'max_attempts' => 3,
        ]);
    }

    private function runListingJob(CrawlQueue $row, CrawlerxFetchResult $result): void
    {
        $client = new FakeCrawlerxClient(
            $result,
            CrawlerxFetchResult::failure(CrawlerxClient::ERROR_PARSE_FAILED, 'unused'),
        );
        $this->app->instance(CrawlerxClient::class, $client);
        $this->app->call([new FetchListingJob($row->id), 'handle']);
    }

    /** @param list<array<string, mixed>> $attempts */
    private function successfulResult(array $attempts): CrawlerxFetchResult
    {
        return CrawlerxFetchResult::success(new CrawlListResultDto(
            url: 'https://latency.test/list',
            page: 1,
            entityType: 'movie',
            items: [],
            pagination: new CrawlPaginationDto(null, null, null, null, false),
        ))->withAttempts($attempts);
    }

    /** @return array<string, mixed> */
    private function attempt(int $durationMs): array
    {
        return [
            'method' => 'http',
            'started_at' => now()->toIso8601String(),
            'duration_ms' => $durationMs,
            'outcome' => 'ok',
        ];
    }
}
