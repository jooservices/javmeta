<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Jobs\FetchDetailJob;
use App\Jobs\FetchGalleryJob;
use App\Jobs\FetchListingJob;
use App\Jobs\FetchPerformerDetailJob;
use App\Jobs\FetchPerformerListingJob;
use App\Models\CrawlQueue;
use App\Models\Source;
use JOOservices\Client\Client\ClientBuilder;
use JOOservices\Client\Testing\HttpFakeRegistry;
use JOOservices\Client\Testing\TestResponse;
use JOOservices\Client\Testing\TestResponseSequence;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Runs the real jooservices/crawlerx stack inside javmeta jobs with only the
 * HTTP transport faked (jooservices/client ClientBuilder::fake()). While the
 * client is faked, crawlerx uses its HTTP-only fetch chain. Fixtures are the
 * live captures in tests/Fixtures/crawlerx (tools/sync-crawlerx-fixtures.php).
 */
trait RunsCrawlerxFixtures
{
    protected HttpFakeRegistry $crawlerxHttp;

    /**
     * @return array<string, array{string, string, string, string}> name => [slug, kind, url, body path]
     */
    public static function crawlerxFixtures(?string $kind = null): array
    {
        $cases = [];
        foreach (glob(dirname(__DIR__) . '/Fixtures/crawlerx/*/*.meta.json') ?: [] as $metaPath) {
            /** @var array{source_url: string, target_type: string} $meta */
            $meta = json_decode((string) file_get_contents($metaPath), true, flags: JSON_THROW_ON_ERROR);
            if ($kind !== null && $meta['target_type'] !== $kind) {
                continue;
            }

            $slug = basename(dirname($metaPath));
            $bodyPath = substr($metaPath, 0, -strlen('.meta.json'));
            $cases[$slug . '/' . basename($bodyPath)] = [$slug, $meta['target_type'], $meta['source_url'], $bodyPath];
        }

        return $cases;
    }

    protected function fakeCrawlerxHttp(): void
    {
        // Unmatched requests get the registry default (404).
        $this->crawlerxHttp = ClientBuilder::fake();
    }

    protected function stopFakingCrawlerxHttp(): void
    {
        ClientBuilder::clearFake();
    }

    protected function respondWith(string $url, ResponseInterface|Throwable ...$responses): void
    {
        $sequence = new TestResponseSequence();
        foreach ($responses as $response) {
            $sequence->push($response);
        }

        ClientBuilder::respond('GET', $url, $sequence);
    }

    protected function respondWithFixture(string $url, string $bodyPath): void
    {
        $this->respondWith($url, TestResponse::make(200, ['Content-Type' => 'text/html; charset=utf-8'], (string) file_get_contents($bodyPath)));
    }

    /** @return list<string> */
    protected function requestedUrls(): array
    {
        return array_map(
            static fn($recorded): string => (string) $recorded->request->getUri(),
            $this->crawlerxHttp->recorded(),
        );
    }

    protected function source(string $slug): Source
    {
        $baseUrl = config("javmeta_sources.sources.{$slug}.base_url");

        return Source::factory()->create([
            'slug' => $slug,
            'base_url' => is_string($baseUrl) ? $baseUrl : 'https://' . $slug . '.test',
        ]);
    }

    protected function claimedRow(string $slug, string $url, string $kind): CrawlQueue
    {
        return CrawlQueue::factory()->create([
            'source_slug' => $slug,
            'url' => $url,
            'kind' => $kind,
            'status' => CrawlQueue::STATUS_CLAIMED,
            'attempts' => 1,
            'max_attempts' => 3,
        ]);
    }

    protected function dispatchFor(CrawlQueue $row): void
    {
        match ($row->kind) {
            CrawlQueue::KIND_LISTING => FetchListingJob::dispatch($row->id),
            CrawlQueue::KIND_DETAIL => FetchDetailJob::dispatch($row->id),
            CrawlQueue::KIND_PERFORMER_LISTING => FetchPerformerListingJob::dispatch($row->id),
            CrawlQueue::KIND_PERFORMER_DETAIL => FetchPerformerDetailJob::dispatch($row->id),
            CrawlQueue::KIND_GALLERY => FetchGalleryJob::dispatch($row->id),
        };
    }
}
