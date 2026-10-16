<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\FetchDetailJob;
use App\Jobs\FetchGalleryJob;
use App\Jobs\FetchListingJob;
use App\Jobs\FetchPerformerDetailJob;
use App\Jobs\FetchPerformerListingJob;
use App\Models\CrawlQueue;
use App\Services\Crawler\CrawlerxClient;
use App\Services\Crawler\CrawlerxFetchResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeCrawlerxClient;
use Tests\TestCase;

final class CrawlJobStaleGuardTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('staleStatusesProvider')]
    public function test_non_claimed_rows_are_not_fetched_or_changed(string $jobClass, string $kind, string $status): void
    {
        $row = CrawlQueue::factory()->create([
            'source_slug' => fake()->slug(2),
            'url' => fake()->unique()->url(),
            'kind' => $kind,
            'status' => $status,
            'attempts' => fake()->numberBetween(1, 3),
            'last_error' => fake()->sentence(),
        ]);
        $original = $row->only(['status', 'attempts', 'last_error']);
        $client = $this->bindFakeClient();

        $jobClass::dispatch($row->id);

        self::assertSame(0, $client->calls);
        $this->assertDatabaseHas('crawl_queue', ['id' => $row->id, ...$original]);
    }

    #[DataProvider('jobProvider')]
    public function test_missing_rows_are_not_fetched_or_raised(string $jobClass, string $kind): void
    {
        $client = $this->bindFakeClient();
        $missingId = (int) CrawlQueue::query()->max('id') + 1;

        $jobClass::dispatch($missingId);

        self::assertSame(0, $client->calls);
        $this->assertDatabaseMissing('crawl_queue', ['id' => $missingId]);
    }

    #[DataProvider('jobProvider')]
    public function test_stale_row_emits_crawl_job_skipped_ops_event(string $jobClass, string $kind): void
    {
        config()->set([
            'openobserve.enabled' => true,
            'openobserve.url' => 'http://openobserve.test',
            'openobserve.org' => 'default',
            'openobserve.stream_logs' => 'javmeta_logs',
            'openobserve.email' => 'root@javmeta.local',
            'openobserve.password' => 'secret',
            'openobserve.timeout' => 1,
            'openobserve.trace_sample_rate' => 1.0,
        ]);
        Http::fake([
            'openobserve.test/*' => Http::response(['code' => 200], 200),
        ]);

        $row = CrawlQueue::factory()->create([
            'source_slug' => fake()->slug(2),
            'url' => fake()->unique()->url(),
            'kind' => $kind,
            'status' => CrawlQueue::STATUS_DONE,
        ]);
        $this->bindFakeClient();

        $jobClass::dispatch($row->id);

        $matched = false;
        foreach (Http::recorded() as $pair) {
            $decoded = json_decode($pair[0]->body(), true);
            if (! is_array($decoded)) {
                continue;
            }

            $payload = array_key_exists(0, $decoded) ? ($decoded[0] ?? []) : $decoded;
            if (
                is_array($payload)
                && ($payload['event'] ?? null) === 'ops'
                && ($payload['kind'] ?? null) === $kind
                && (int) ($payload['crawl_queue_id'] ?? 0) === $row->id
                && ($payload['status'] ?? null) === CrawlQueue::STATUS_DONE
                && ($payload['job'] ?? null) === $jobClass
            ) {
                $matched = true;

                break;
            }
        }

        self::assertTrue($matched, 'crawl_job_skipped payload missing; recorded=' . json_encode(Http::recorded()));
    }

    /**
     * @return iterable<string, array{0: class-string, 1: string}>
     */
    public static function jobProvider(): iterable
    {
        yield 'listing' => [FetchListingJob::class, CrawlQueue::KIND_LISTING];
        yield 'detail' => [FetchDetailJob::class, CrawlQueue::KIND_DETAIL];
        yield 'performer listing' => [FetchPerformerListingJob::class, CrawlQueue::KIND_PERFORMER_LISTING];
        yield 'performer detail' => [FetchPerformerDetailJob::class, CrawlQueue::KIND_PERFORMER_DETAIL];
        yield 'gallery' => [FetchGalleryJob::class, CrawlQueue::KIND_GALLERY];
    }

    /**
     * @return iterable<string, array{0: class-string, 1: string, 2: string}>
     */
    public static function staleStatusesProvider(): iterable
    {
        foreach (self::jobProvider() as $name => [$jobClass, $kind]) {
            foreach ([CrawlQueue::STATUS_DONE, CrawlQueue::STATUS_FAILED, CrawlQueue::STATUS_PENDING] as $status) {
                yield $name . ' ' . $status => [$jobClass, $kind, $status];
            }
        }
    }

    private function bindFakeClient(): FakeCrawlerxClient
    {
        $failure = CrawlerxFetchResult::failure(CrawlerxClient::ERROR_PARSE_FAILED, fake()->sentence());
        $client = new FakeCrawlerxClient($failure, $failure, $failure, $failure, $failure);
        $this->app->instance(CrawlerxClient::class, $client);

        return $client;
    }
}
