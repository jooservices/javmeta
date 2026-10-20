<?php

declare(strict_types=1);

namespace Tests\Integration;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use App\Services\Search\ElasticsearchHttpClient;
use MongoDB\Client as MongoClient;
use MongoDB\Collection;
use Tests\Support\RunsCrawlerxFixtures;

/**
 * Real Postgres, Mongo and Elasticsearch (docker/ci/compose.integration.yml,
 * run through docker/ci/integration). Postgres is migrated per test so persister transactions can commit;
 * the Mongo archive database and the test ES indices are dropped before each
 * test. Only the embedder is faked (it is a large model image).
 */
abstract class IntegrationTestCase extends BaseTestCase
{
    use DatabaseMigrations;
    use RunsCrawlerxFixtures;

    protected const EMBED_DIM = 384;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('queue.default', 'sync');
        $this->resetMongo();
        $this->resetElasticsearch();
        $this->fakeEmbedder();
        $this->fakeCrawlerxHttp();
    }

    protected function tearDown(): void
    {
        $this->stopFakingCrawlerxHttp();

        parent::tearDown();
    }

    protected function mongoCollection(string $siteSlug): Collection
    {
        return $this->mongo()->selectDatabase((string) config('mongodb.database'))->selectCollection($siteSlug);
    }

    /** @return array<string, mixed>|null */
    protected function esDocument(string $index, string $id): ?array
    {
        // Elasticsearch 9 leaves dense_vector fields out of _source unless asked for them.
        $response = $this->es()->get('integration_read', "/{$index}/_doc/{$id}", 10, ['_source_exclude_vectors' => 'false']);

        return $response->successful() && $response->json('found') === true ? (array) $response->json('_source') : null;
    }

    protected function esCount(string $index): int
    {
        // GET avoids sending a JSON body, which _refresh rejects.
        self::assertTrue($this->es()->get('integration_refresh', "/{$index}/_refresh", 10)->successful(), "refresh of {$index} failed");

        return (int) $this->es()->get('integration_count', "/{$index}/_count", 10)->json('count');
    }

    private function resetMongo(): void
    {
        // Collections are dropped one by one: the least-privilege test user (readWrite) cannot dropDatabase.
        $database = $this->mongo()->selectDatabase((string) config('mongodb.database'));
        foreach ($database->listCollectionNames() as $collection) {
            if ($collection === '_workspaces') {
                continue; // the ownership marker of the shared Docker project database
            }
            $database->dropCollection($collection);
        }
    }

    private function resetElasticsearch(): void
    {
        foreach ([config('elasticsearch.movies_index'), config('elasticsearch.performers_index')] as $index) {
            $this->es()->delete('integration_reset', '/' . $index, acceptedStatuses: [404]);
        }

        self::assertSame(0, Artisan::call('es:setup'), Artisan::output());
    }

    private function fakeEmbedder(): void
    {
        $embedder = rtrim((string) config('elasticsearch.embedder_url'), '/');

        // Only the embedder is stubbed; Elasticsearch requests still go out.
        Http::fake([
            $embedder . '/*' => static function ($request) {
                $inputs = (array) ($request->data()['input'] ?? []);

                return Http::response(['data' => array_map(
                    static fn(): array => ['embedding' => array_fill(0, self::EMBED_DIM, 0.01)],
                    $inputs,
                )]);
            },
        ]);
    }

    protected function mongo(): MongoClient
    {
        $uri = config('mongodb.uri');

        return new MongoClient(is_string($uri) && $uri !== ''
            ? $uri
            : sprintf('mongodb://%s:%d', config('mongodb.host'), config('mongodb.port')));
    }

    protected function es(): ElasticsearchHttpClient
    {
        return app(ElasticsearchHttpClient::class);
    }
}
