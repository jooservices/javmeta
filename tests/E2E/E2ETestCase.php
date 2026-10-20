<?php

declare(strict_types=1);

namespace Tests\E2E;

use App\Models\ApiKey;
use App\Models\CrawlQueue;
use App\Models\Genre;
use App\Models\Movie;
use App\Models\MovieCode;
use App\Models\MovieGenre;
use App\Models\MoviePerformer;
use App\Models\MovieSource;
use App\Models\Performer;
use App\Models\PerformerAlias;
use App\Models\PerformerMedia;
use App\Services\Auth\ApiKeyService;
use App\Services\Crawl\WorkerHeartbeat;
use App\Support\Code\NormalizedCode;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use App\Services\Search\ElasticsearchHttpClient;
use JOOservices\Client\Testing\TestResponse;
use MongoDB\Client as MongoClient;
use RuntimeException;
use Tests\E2E\Support\ApiKeyCredential;
use Tests\E2E\Support\CatalogSeed;
use Tests\E2E\Support\CrawlResult;
use Tests\E2E\Support\FixtureSeed;
use Tests\Support\RunsCrawlerxFixtures;
use Tests\TestCase;

/** Shared-service E2E harness. It resets only explicitly named javmeta_it namespaces. */
abstract class E2ETestCase extends TestCase
{
    use RunsCrawlerxFixtures;

    private static string $baseUrl;
    private static string $ownerToken;
    private static string $mcpToken;
    private static string $embedderUrl;

    /** @var resource|null */
    private static $appProcess = null;

    /** @var resource|null */
    private static $embedderProcess = null;

    private static string $appLog = '';
    private static string $embedderLog = '';
    private static string $logDirectory = '';

    protected function setUp(): void
    {
        parent::setUp();

        self::useTestDatabase();
        self::initializeProcesses();
        config()->set('javmeta_auth.owner_admin_token', self::$ownerToken);
        config()->set('javmeta_auth.mcp_api_key', self::$mcpToken);
        config()->set('queue.default', 'sync');
        config()->set('elasticsearch.embedder_url', self::$embedderUrl);
        $this->fakeCrawlerxHttp();
    }

    protected function tearDown(): void
    {
        $this->stopFakingCrawlerxHttp();
        self::cleanupNamespaces();

        parent::tearDown();
    }

    public static function tearDownAfterClass(): void
    {
        self::stopProcess(self::$appProcess);
        self::$appProcess = null;
        self::stopProcess(self::$embedderProcess);
        self::$embedderProcess = null;
        if (self::$appLog !== '') {
            @unlink(self::$appLog);
        }
        if (self::$embedderLog !== '') {
            @unlink(self::$embedderLog);
        }
        if (self::$logDirectory !== '') {
            @rmdir(self::$logDirectory);
        }

        parent::tearDownAfterClass();
    }

    /** @return list<ApiKeyCredential> */
    protected function resetTestState(): array
    {
        self::assertNamespaces();
        self::resetPostgres();
        self::resetMongo();
        self::resetElasticsearch();

        $consumer = app(ApiKeyService::class)->create('JAVMETA E2E consumer');
        $mcp = ApiKey::query()->create([
            'prefix' => substr(self::$mcpToken, 0, 12),
            'key_hash' => hash('sha256', self::$mcpToken),
            'label' => 'JAVMETA E2E MCP',
            'status' => ApiKey::STATUS_ACTIVE,
            'revoked_at' => null,
            'abuse_rpm' => 600,
            'created_at' => now(),
        ]);

        app(WorkerHeartbeat::class)->beat('javmeta-e2e');

        return [
            new ApiKeyCredential((int) $consumer->model->getKey(), $consumer->plaintext),
            new ApiKeyCredential((int) $mcp->getKey(), self::$mcpToken, 'mcp'),
        ];
    }

    protected function e2eBaseUrl(): string
    {
        return self::$baseUrl;
    }

    protected function journeyContext(): Context
    {
        return new Context(self::$baseUrl, self::$ownerToken);
    }

    protected function seedCatalog(): CatalogSeed
    {
        $performer = Performer::factory()->create([
            'source_slug' => 'onejav',
            'external_id' => 'javmeta-e2e-performer',
            'name_romaji' => 'E2E Performer',
            'name_kanji' => '出演者',
            'name_kana' => 'しゅつえんしゃ',
            'image_url' => 'https://example.test/e2e-performer.jpg',
        ]);
        $alias = 'E2E Alias';
        PerformerAlias::query()->create(['performer_id' => $performer->id, 'alias' => $alias, 'kind' => 'stage_name']);
        PerformerMedia::factory()->create([
            'performer_id' => $performer->id,
            'kind' => PerformerMedia::KIND_IMAGE,
            'url' => 'https://example.test/e2e-performer.jpg',
            'source_slug' => 'onejav',
        ]);
        $genre = Genre::factory()->create(['label_normalized' => 'drama', 'label_raw' => 'Drama']);

        $codes = ['E2E-001', 'E2E-002', 'E2E-003', 'E2E-004', 'SSIS-001', 'E2E-005', 'E2E-006', 'E2E-007'];
        foreach ($codes as $index => $code) {
            $normalized = NormalizedCode::from($code);
            $movie = Movie::factory()->create([
                'display_code' => $normalized->display(),
                'code_normalized' => $normalized->value(),
                'title_en' => 'E2E feature ' . $code,
                'release_date' => sprintf('%04d-06-15', 2026 - $index),
                'runtime_minutes' => 90,
                'censored' => Movie::CENSORED_CENSORED,
            ]);
            MovieCode::query()->create([
                'movie_id' => $movie->id,
                'code' => $normalized->display(),
                'code_normalized' => $normalized->value(),
                'kind' => MovieCode::KIND_DVD,
                'source_slug' => 'e2e',
                'source_url' => null,
                'crawled_at' => now(),
            ]);
            MoviePerformer::query()->create([
                'movie_id' => $movie->id,
                'performer_id' => $performer->id,
                'source_slug' => 'e2e',
                'crawled_at' => now(),
            ]);

            if (in_array($normalized->display(), ['E2E-002', 'SSIS-001'], true)) {
                MovieGenre::query()->create([
                    'movie_id' => $movie->id,
                    'genre_id' => $genre->id,
                    'source_slug' => 'e2e',
                    'crawled_at' => now(),
                ]);
            }
        }

        return new CatalogSeed(
            primaryCode: 'SSIS-001',
            movieCodes: $codes,
            paginationCodes: ['E2E-001', 'E2E-002', 'E2E-003', 'E2E-004', 'SSIS-001', 'E2E-005', 'E2E-006', 'E2E-007'],
            filteredCodes: ['E2E-002', 'SSIS-001'],
            performerId: (int) $performer->id,
            performerUuid: (string) $performer->uuid,
            performerRomaji: (string) $performer->name_romaji,
            performerKanji: (string) $performer->name_kanji,
            performerAlias: $alias,
            genre: 'drama',
        );
    }

    protected function seedFixtureSource(): FixtureSeed
    {
        $fixtures = static::crawlerxFixtures(CrawlQueue::KIND_DETAIL);
        $fixture = $fixtures['onejav/detail-sample-1.html'] ?? null;
        if (! is_array($fixture)) {
            throw new RuntimeException('Required onejav detail fixture is missing.');
        }

        [$slug, $kind, $url, $bodyPath] = $fixture;
        $this->source($slug);
        $row = $this->claimedRow($slug, $url, $kind);
        $this->respondWithFixture($url, $bodyPath);

        return new FixtureSeed($slug, $kind, $url, $bodyPath, (int) $row->id);
    }

    protected function runCrawlOnce(FixtureSeed $seed): CrawlResult
    {
        $row = CrawlQueue::query()->findOrFail($seed->queueId);
        $this->dispatchFor($row);
        $row->refresh();
        if ($row->status !== CrawlQueue::STATUS_DONE) {
            throw new RuntimeException('Fixture crawl queue did not finish successfully.');
        }

        $source = MovieSource::query()->with('movie')->where('site_slug', $seed->slug)->where('source_url', $seed->url)->first();
        if ($source?->movie === null) {
            throw new RuntimeException('Fixture crawl did not persist a movie source.');
        }

        // Journeys search by this text, so fall back to the Japanese title and then the code when no English title exists.
        $title = (string) ($source->movie->title_en ?: $source->movie->title_jp ?: $source->movie->display_code);

        return new CrawlResult($seed->slug, $seed->url, (string) $source->movie->display_code, $title);
    }

    /** The source answers 503 so the detail crawl fails and the queue row is retried later, not completed. */
    protected function runCrawlOutage(FixtureSeed $seed): void
    {
        $this->respondWith($seed->url, TestResponse::make(503, ['Retry-After' => '30'], 'Service unavailable'));
        $row = CrawlQueue::query()->findOrFail($seed->queueId);
        $this->dispatchFor($row);
        $row->refresh();
        if ($row->status === CrawlQueue::STATUS_DONE) {
            throw new RuntimeException('The crawl finished although its source was down.');
        }
        if (MovieSource::query()->where('site_slug', $seed->slug)->where('source_url', $seed->url)->exists()) {
            throw new RuntimeException('A movie was persisted although its source was down.');
        }
    }

    /** The source answers again and the scheduler hands the failed row back to a worker. */
    protected function recoverSource(FixtureSeed $seed): CrawlResult
    {
        $this->stopFakingCrawlerxHttp();
        $this->fakeCrawlerxHttp();
        $this->respondWithFixture($seed->url, $seed->bodyPath);
        CrawlQueue::query()->whereKey($seed->queueId)->update([
            'status' => CrawlQueue::STATUS_CLAIMED,
            'claimed_at' => now(),
            'started_at' => null,
            'locked_by' => null,
            'next_attempt_at' => null,
        ]);

        return $this->runCrawlOnce($seed);
    }

    private static function initializeProcesses(): void
    {
        if (isset(self::$baseUrl)) {
            return;
        }

        self::assertNamespaces();
        $directory = dirname(__DIR__, 2) . '/.tmp/e2e-' . bin2hex(random_bytes(8));
        if (! mkdir($directory, 0700, true)) {
            throw new RuntimeException('Could not create the private E2E log directory.');
        }
        self::$logDirectory = $directory;
        chmod($directory, 0700);

        self::$ownerToken = 'e2e-owner-' . bin2hex(random_bytes(32));
        self::$mcpToken = 'jvm_' . bin2hex(random_bytes(24));
        $embedderPort = self::freePort();
        $appPort = self::freePort();
        self::$embedderUrl = 'http://127.0.0.1:' . $embedderPort;
        self::$baseUrl = 'http://127.0.0.1:' . $appPort;
        self::$appLog = $directory . '/app-server.log';
        self::$embedderLog = $directory . '/embedder-server.log';

        putenv('JAVMETA_OWNER_ADMIN_TOKEN=' . self::$ownerToken);
        putenv('JAVMETA_MCP_API_KEY=' . self::$mcpToken);
        putenv('EMBEDDER_URL=' . self::$embedderUrl);
        config()->set('elasticsearch.embedder_url', self::$embedderUrl);

        self::$embedderProcess = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $embedderPort, dirname(__DIR__) . '/Support/embedder-router.php'],
            [0 => ['pipe', 'r'], 1 => ['file', self::$embedderLog, 'a'], 2 => ['file', self::$embedderLog, 'a']],
            $pipes,
        );
        if (! is_resource(self::$embedderProcess)) {
            throw new RuntimeException('Could not start the local E2E embedder.');
        }
        if (isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]);
        }

        self::$appProcess = proc_open(
            [PHP_BINARY, 'artisan', 'serve', '--host=127.0.0.1', '--port=' . $appPort],
            [0 => ['pipe', 'r'], 1 => ['file', self::$appLog, 'a'], 2 => ['file', self::$appLog, 'a']],
            $pipes,
        );
        if (! is_resource(self::$appProcess)) {
            throw new RuntimeException('Could not start the JAVmeta E2E HTTP application.');
        }
        if (isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]);
        }

        self::waitForHttp(self::$embedderUrl . '/healthz');
        self::waitForHttp(self::$baseUrl . '/api/health');
    }

    private static function assertNamespaces(): void
    {
        $database = (string) config('database.connections.pgsql.database');
        $mongoDatabase = (string) config('mongodb.database');
        $movieIndex = (string) config('elasticsearch.movies_index');
        $performerIndex = (string) config('elasticsearch.performers_index');
        $stream = (string) config('openobserve.stream_logs');

        foreach (['Postgres database' => $database, 'Mongo database' => $mongoDatabase, 'movie index' => $movieIndex, 'performer index' => $performerIndex, 'OpenObserve stream' => $stream] as $label => $name) {
            if (! self::isTestNamespace($name, $label === 'Postgres database')) {
                throw new RuntimeException($label . ' must use a javmeta test namespace (javmeta_test, javmeta_wt<id> or the CI javmeta_it).');
            }
        }
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('The E2E suite requires the PostgreSQL test database.');
        }
    }

    private static function useTestDatabase(): void
    {
        $database = getenv('JAVMETA_E2E_POSTGRES_DATABASE');
        if (! is_string($database) || $database === '') {
            throw new RuntimeException('The E2E PostgreSQL database was not configured.');
        }

        putenv('DB_CONNECTION=pgsql');
        putenv('DB_DATABASE=' . $database);
        $_ENV['DB_CONNECTION'] = 'pgsql';
        $_ENV['DB_DATABASE'] = $database;
        $_SERVER['DB_CONNECTION'] = 'pgsql';
        $_SERVER['DB_DATABASE'] = $database;
        config()->set('database.default', 'pgsql');
        config()->set('database.connections.pgsql.database', $database);
        DB::purge('pgsql');
        DB::setDefaultConnection('pgsql');
    }

    private static function resetPostgres(): void
    {
        $tables = DB::select("select tablename from pg_tables where schemaname = 'public' and tablename <> 'migrations'");
        $wrapped = [];
        foreach ($tables as $row) {
            $wrapped[] = DB::getQueryGrammar()->wrapTable((string) $row->tablename);
        }
        if ($wrapped !== []) {
            DB::statement('TRUNCATE TABLE ' . implode(', ', $wrapped) . ' RESTART IDENTITY CASCADE');
        }
    }

    /** Test namespaces: shared javmeta_test / javmeta_wt<id> (provision-project) and the isolated CI javmeta_it. */
    private static function isTestNamespace(string $name, bool $allowLegacyPostgres = false): bool
    {
        $prefixes = ['javmeta_test', 'javmeta_wt', 'javmeta_it'];
        if ($allowLegacyPostgres) {
            $prefixes[] = 'jvmeta_it';
        }

        foreach ($prefixes as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private static function resetMongo(): void
    {
        $databaseName = (string) config('mongodb.database');
        if (! self::isTestNamespace($databaseName)) {
            throw new RuntimeException('Mongo cleanup refused a database outside the javmeta test namespaces.');
        }
        $uri = config('mongodb.uri');
        $client = new MongoClient(is_string($uri) && $uri !== ''
            ? $uri
            : sprintf('mongodb://%s:%d', config('mongodb.host'), config('mongodb.port')));
        $database = $client->selectDatabase($databaseName);
        foreach ($database->listCollectionNames() as $collection) {
            if ($collection === '_workspaces') {
                continue; // the ownership marker of the shared Docker project database
            }
            $database->dropCollection($collection);
        }
    }

    private static function resetElasticsearch(): void
    {
        self::deleteElasticsearchIndexes();
        if (Artisan::call('es:setup') !== 0) {
            throw new RuntimeException('Could not recreate the E2E Elasticsearch indexes.');
        }
    }

    private static function cleanupNamespaces(): void
    {
        self::assertNamespaces();
        self::resetPostgres();
        self::resetMongo();
        self::deleteElasticsearchIndexes();
    }

    private static function deleteElasticsearchIndexes(): void
    {
        $indexes = [(string) config('elasticsearch.movies_index'), (string) config('elasticsearch.performers_index')];
        foreach ($indexes as $index) {
            if (! self::isTestNamespace($index)) {
                throw new RuntimeException('Elasticsearch cleanup refused an index outside the javmeta test namespaces.');
            }
            $response = app(ElasticsearchHttpClient::class)->delete('e2e_cleanup', '/' . $index, acceptedStatuses: [404]);
            if (! $response->successful() && $response->status() !== 404) {
                throw new RuntimeException('Could not clear an E2E Elasticsearch index.');
            }
        }
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage);
        if (! is_resource($socket)) {
            throw new RuntimeException('Could not reserve a local E2E port (' . $errorNumber . ').');
        }
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        if (! is_string($address) || ! str_contains($address, ':')) {
            throw new RuntimeException('Could not determine a local E2E port.');
        }

        return (int) substr($address, strrpos($address, ':') + 1);
    }

    private static function waitForHttp(string $url): void
    {
        $deadline = microtime(true) + 45;
        do {
            try {
                Http::timeout(2)->get($url);

                return;
            } catch (\Throwable) {
                usleep(100_000);
            }
        } while (microtime(true) < $deadline);

        throw new RuntimeException('An E2E HTTP process did not become reachable.');
    }

    /** @param resource|null $process */
    private static function stopProcess(mixed $process): void
    {
        if (is_resource($process)) {
            proc_terminate($process);
            proc_close($process);
        }
        $process = null;
    }
}
