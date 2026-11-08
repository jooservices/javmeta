<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\CrawlQueue;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * A crawl reports its fetch outcome to a real OpenObserve: javmeta reads the
 * crawlerx result and ingests a `crawl_fetch` log record that can be queried
 * back from the stream.
 */
final class ObservabilityPipelineTest extends IntegrationTestCase
{
    private const WAIT_SECONDS = 60;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('openobserve.enabled', true);
        $this->waitForOpenObserve();
    }

    public function test_crawl_fetch_outcome_is_queryable_in_openobserve(): void
    {
        $startedUs = (int) (microtime(true) * 1_000_000);
        [$slug, $kind, $url, $bodyPath] = self::crawlerxFixtures(CrawlQueue::KIND_DETAIL)['onejav/detail-sample-1.html'];
        $this->source($slug);
        $row = $this->claimedRow($slug, $url, $kind);
        $this->respondWithFixture($url, $bodyPath);

        $this->dispatchFor($row);

        $record = $this->waitForFetchRecord($slug, $startedUs);
        self::assertNotNull($record, 'crawl_fetch record never became queryable in OpenObserve');
        self::assertSame('detail', $record['crawl_type']);
        self::assertTrue((bool) $record['ok']);
        self::assertGreaterThanOrEqual(0, (int) $record['duration_ms']);
    }

    private function waitForOpenObserve(): void
    {
        $deadline = time() + self::WAIT_SECONDS;
        while (time() < $deadline) {
            try {
                if ($this->obs()->get('/healthz')->successful()) {
                    return;
                }
            } catch (Throwable) {
                // not up yet
            }
            usleep(500_000);
        }

        self::fail('OpenObserve did not become healthy');
    }

    /** @return array<string, mixed>|null */
    private function waitForFetchRecord(string $slug, int $startedUs): ?array
    {
        $stream = (string) config('openobserve.stream_logs');
        $org = (string) config('openobserve.org');
        $deadline = time() + self::WAIT_SECONDS;

        while (time() < $deadline) {
            $response = $this->obs()->post("/api/{$org}/_search", ['query' => [
                'sql' => sprintf("SELECT * FROM \"%s\" WHERE event = 'crawl_fetch' AND source_slug = '%s'", $stream, $slug),
                'start_time' => $startedUs - 60_000_000,
                'end_time' => (int) (microtime(true) * 1_000_000) + 60_000_000,
                'from' => 0,
                'size' => 1,
            ]]);
            $hit = $response->successful() ? $response->json('hits.0') : null;
            if (is_array($hit)) {
                return $hit;
            }
            usleep(1_000_000);
        }

        return null;
    }

    private function obs(): PendingRequest
    {
        return Http::baseUrl((string) config('openobserve.url'))
            ->withBasicAuth((string) config('openobserve.email'), (string) config('openobserve.password'))
            ->acceptJson()
            ->timeout(10);
    }
}
