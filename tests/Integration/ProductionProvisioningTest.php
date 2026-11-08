<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\Movie;
use App\Models\Performer;
use App\Services\Search\ElasticsearchHttpClient;
use App\Services\Search\ElasticsearchIndexer;
use Illuminate\Support\Facades\Http;
use Illuminate\Redis\RedisManager;
use Laravel\Horizon\MasterSupervisor;
use Laravel\Horizon\Repositories\RedisMasterSupervisorRepository;
use PDO;
use PDOException;
use Redis;
use RedisException;
use Symfony\Component\Process\Process;

/** Production names exist only in the disposable CI project, never shared services. */
final class ProductionProvisioningTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        if (getenv('JAVMETA_PRODUCTION_PROVISIONING') !== '1') {
            self::markTestSkipped('Production provisioning requires the disposable docker/ci/integration harness.');
        }
        self::assertSame('postgres', getenv('DB_HOST'), 'Provision only disposable CI services.');
        self::assertSame('mongo', getenv('MONGO_HOST'));
        self::assertSame('http://elasticsearch:9200', getenv('ELASTICSEARCH_HOST'));
        parent::setUp();
    }

    public function test_postgres_provisioning_is_repeatable_and_grants_current_and_future_read_only_access(): void
    {
        $password = bin2hex(random_bytes(24));
        $environment = [
            'PGHOST' => (string) config('database.connections.pgsql.host'),
            'PGPORT' => (string) config('database.connections.pgsql.port'),
            'PGUSER' => (string) config('database.connections.pgsql.username'),
            'PGPASSWORD' => (string) config('database.connections.pgsql.password'),
            'XCRAWLER_PASSWORD' => $password,
            'XCRAWLER_RO_PASSWORD' => $password,
        ];
        $script = ['psql', '-X', '-q', '-d', 'postgres', '-f', base_path('deploy/production/provision/postgres.sql')];
        self::assertNotSame(0, (new Process($script, base_path(), array_replace($environment, ['XCRAWLER_PASSWORD' => ''])))->run(), 'An empty password must fail before any provisioning.');
        self::assertTrue((new Process($script, base_path(), $environment))->run() === 0, 'First PostgreSQL provisioning failed.');
        $owner = $this->postgres('xcrawler', $password);
        $owner->exec('DROP TABLE IF EXISTS production_future, production_existing CASCADE');
        $owner->exec('CREATE TABLE production_existing (id bigserial PRIMARY KEY, value text)');
        $owner->exec("INSERT INTO production_existing(value) VALUES ('existing')");
        self::assertTrue((new Process($script, base_path(), $environment))->run() === 0, 'Repeat PostgreSQL provisioning failed.');
        $owner->exec('CREATE TABLE production_future (id bigserial PRIMARY KEY, value text)');
        $owner->exec("INSERT INTO production_future(value) VALUES ('future')");
        $reader = $this->postgres('xcrawler_ro', $password);
        self::assertSame('existing', $reader->query('SELECT value FROM production_existing')->fetchColumn());
        self::assertSame('future', $reader->query('SELECT value FROM production_future')->fetchColumn());
        self::assertSame(1, (int) $reader->query('SELECT last_value FROM production_future_id_seq')->fetchColumn());
        self::assertSame('pg_trgm', $owner->query("SELECT extname FROM pg_extension WHERE extname = 'pg_trgm'")->fetchColumn());
        // Do not rely solely on default_transaction_read_only: privileges must still deny writes if it is disabled.
        $reader->exec('SET default_transaction_read_only = off');
        foreach (['INSERT INTO production_future(value) VALUES (\'no\')', 'DELETE FROM production_existing', 'CREATE TABLE forbidden (id int)'] as $sql) {
            try {
                $reader->exec($sql);
                self::fail('Read-only role could write.');
            } catch (PDOException $exception) {
                self::assertSame('42501', $exception->getCode());
            }
        }
    }

    public function test_mongodb_provisioning_creates_the_xcrawler_read_write_user(): void
    {
        $users = $this->mongo()->selectDatabase('xcrawler')->command(['usersInfo' => 'xcrawler'])->toArray();
        self::assertCount(1, $users);
        $response = (array) $users[0];
        self::assertCount(1, $response['users']);
        $user = (array) $response['users'][0];
        $roles = array_map(static fn($role): array => (array) $role, iterator_to_array($user['roles']));
        self::assertSame([['role' => 'readWrite', 'db' => 'xcrawler']], $roles);
    }

    public function test_redis_acl_runs_existing_queue_test_and_supports_cache_horizon_lua_and_transactions(): void
    {
        $password = bin2hex(random_bytes(24));
        $admin = new Redis();
        $admin->connect((string) config('database.redis.default.host'), (int) config('database.redis.default.port'));
        $admin->del(['xcrawler:cache:proof', 'xcrawler:lua', 'xcrawler:tx', 'xcrawler:horizon:proof']);
        $admin->close();
        $script = new Process(['bash', base_path('deploy/production/provision/redis-acl.sh')], base_path(), [
            'XCRAWLER_REDIS_PASSWORD' => $password,
        ]);
        self::assertSame(0, $script->run(), 'Redis provisioning failed: ' . $script->getErrorOutput());
        self::assertSame(0, $script->run(), 'Repeat Redis provisioning failed.');
        $redis = new Redis();
        $redis->connect((string) config('database.redis.default.host'), (int) config('database.redis.default.port'));
        $redis->auth(['xcrawler', $password]);
        self::assertTrue($redis->set('xcrawler:cache:proof', 'ok'));
        self::assertSame('ok', $redis->get('xcrawler:cache:proof'));
        self::assertSame(1, $redis->eval("return redis.call('incr', KEYS[1])", ['xcrawler:lua'], 1));
        self::assertSame([true, 'ok'], $redis->multi()->set('xcrawler:tx', 'ok')->get('xcrawler:tx')->exec());
        $sha = $redis->script('load', "return redis.call('get', KEYS[1])");
        self::assertSame('ok', $redis->evalSha($sha, ['xcrawler:tx'], 1));
        self::assertTrue($redis->hSet('xcrawler:horizon:proof', 'status', 'running') !== false);
        self::assertContains('xcrawler:horizon:proof', $redis->keys('xcrawler:*'));
        $iterator = null;
        self::assertNotFalse($redis->scan($iterator, 'xcrawler:*', 100));
        self::assertSame(0, $redis->publish('xcrawler:events', 'proof'));
        foreach ([['GET', 'unrelated:key'], ['SET', 'unrelated:key', 'no'], ['FLUSHDB'], ['FLUSHALL'], ['CONFIG', 'GET', '*']] as $command) {
            try {
                self::assertFalse($redis->rawCommand(...$command), 'ACL allowed ' . $command[0]);
                self::assertStringContainsString('NOPERM', (string) $redis->getLastError());
                $redis->clearLastError();
            } catch (RedisException $exception) {
                self::assertStringContainsString('NOPERM', $exception->getMessage());
            }
        }
        // Redis disables DEBUG at server level before checking ACLs; DRYRUN proves the ACL itself also denies it.
        $admin = new Redis();
        $admin->connect((string) config('database.redis.default.host'), (int) config('database.redis.default.port'));
        self::assertSame("User xcrawler has no permissions to run the 'debug' command", $admin->rawCommand('ACL', 'DRYRUN', 'xcrawler', 'DEBUG', 'HELP'));
        $admin->close();
        $queue = new Process([PHP_BINARY, 'vendor/bin/phpunit', '--configuration', 'phpunit.integration.xml', '--filter', 'RedisQueueTest'], base_path(), [
            'REDIS_USERNAME' => 'xcrawler',
            'REDIS_PASSWORD' => $password,
            'REDIS_PREFIX' => 'xcrawler:',
            'HORIZON_PREFIX' => 'xcrawler:horizon:',
        ]);
        $queue->setTimeout(240);
        self::assertSame(0, $queue->run(), $queue->getOutput() . $queue->getErrorOutput());
        self::assertStringContainsString('OK (1 test,', $queue->getOutput());
        $configuration = config('database.redis');
        $configuration['horizon'] = $configuration['default'];
        $configuration['horizon']['username'] = 'xcrawler';
        $configuration['horizon']['password'] = $password;
        $configuration['horizon']['options']['prefix'] = 'xcrawler:horizon:';
        $manager = new RedisManager(app(), 'phpredis', $configuration);
        $repository = new RedisMasterSupervisorRepository($manager);
        $master = new MasterSupervisor('production');
        $repository->update($master);
        self::assertContains($master->name, $repository->names());
        self::assertSame('running', $repository->find($master->name)->status);
        $repository->forget($master->name);
        self::assertNull($repository->find($master->name));
    }

    public function test_elasticsearch_provisioned_key_indexes_and_searches_with_application_mappings(): void
    {
        self::assertSame('javmeta_scenes', config('javmeta_media.scenes_index'));
        $keyFile = tempnam(sys_get_temp_dir(), 'xcrawler-key-');
        self::assertNotFalse($keyFile);
        // Provisioner refuses an existing empty destination rather than overwriting it.
        unlink($keyFile);
        $host = rtrim((string) config('elasticsearch.host'), '/');
        $admin = Http::withBasicAuth((string) config('elasticsearch.username'), (string) config('elasticsearch.password'));
        $admin->post($host . '/_security/api_key/_invalidate', ['name' => 'xcrawler']);
        foreach (['xcrawler_movies', 'xcrawler_performers', 'xcrawler_scenes'] as $index) {
            $admin->delete($host . '/' . $index);
        }
        $transport = $this->elasticsearchTestTransport();
        $environment = [
            'PATH' => $transport . ':' . getenv('PATH'),
            'ELASTICSEARCH_HOST' => 'https://elasticsearch:9200',
            'ES_ADMIN_USER' => (string) config('elasticsearch.username'),
            'ES_ADMIN_PASSWORD' => (string) config('elasticsearch.password'),
            'ES_KEY_FILE' => $keyFile,
        ];
        try {
            $script = new Process(['bash', base_path('deploy/production/provision/elasticsearch.sh')], base_path(), $environment);
            self::assertSame(0, $script->run(), 'Elasticsearch provisioning failed: ' . $script->getErrorOutput());
            $key = trim((string) file_get_contents($keyFile));
            self::assertNotSame('', $key);
            $identity = Http::withHeaders(['Authorization' => 'ApiKey ' . $key])->get($host . '/_security/_authenticate')->json('api_key.id');
            self::assertTrue($admin->put($host . '/_security/api_key/' . $identity, [
                'role_descriptors' => ['xcrawler' => ['cluster' => ['all'], 'indices' => [['names' => ['*'], 'privileges' => ['all']]]]],
            ])->successful());
            self::assertSame(0, $script->run(), 'Repeat Elasticsearch provisioning failed.');
            self::assertSame($key, trim((string) file_get_contents($keyFile)));
            config([
                'elasticsearch.api_key' => $key,
                'elasticsearch.movies_index' => 'xcrawler_movies',
                'elasticsearch.performers_index' => 'xcrawler_performers',
                'javmeta_media.scenes_index' => 'xcrawler_scenes',
            ]);
            $indexer = app(ElasticsearchIndexer::class);
            $movie = Movie::factory()->create(['title_en' => 'Production proof', 'title_jp' => null]);
            $performer = Performer::factory()->create(['name_romaji' => 'Production performer']);
            $indexer->indexMovie($movie);
            $indexer->indexPerformer($performer);
            self::assertSame(1, $this->esCount('xcrawler_movies'));
            self::assertSame(1, $this->esCount('xcrawler_performers'));
            $es = app(ElasticsearchHttpClient::class);
            foreach (['movies' => 'embedding_movie', 'performers' => 'embedding_performer', 'scenes' => 'embedding'] as $kind => $field) {
                $mapping = $es->get('production-mapping', 'xcrawler_' . $kind . '/_mapping');
                self::assertSame(self::EMBED_DIM, $mapping->json('xcrawler_' . $kind . '.mappings.properties.' . $field . '.dims'));
            }
            $search = $es->post('production-proof', 'xcrawler_movies/_search', ['query' => ['match' => ['title_en' => 'Production']]]);
            self::assertSame(1, $search->json('hits.total.value'));
            $vector = array_fill(0, self::EMBED_DIM, 0.01);
            self::assertTrue($es->put('production-scene', 'xcrawler_scenes/_doc/1', ['description' => 'Production scene', 'embedding' => $vector])->successful());
            self::assertSame(1, $this->esCount('xcrawler_scenes'));
            self::assertTrue($es->post('production-knn', 'xcrawler_scenes/_search', ['knn' => ['field' => 'embedding', 'query_vector' => $vector, 'k' => 1, 'num_candidates' => 10]])->successful());
            $host = rtrim((string) config('elasticsearch.host'), '/');
            self::assertSame(403, Http::withHeaders(['Authorization' => 'ApiKey ' . $key])->put($host . '/unrelated_production_proof', [])->status());
            self::assertSame(403, Http::withHeaders(['Authorization' => 'ApiKey ' . $key])->get($host . '/_security/user')->status());
        } finally {
            $admin->post($host . '/_security/api_key/_invalidate', ['name' => 'xcrawler']);
            @unlink($keyFile);
            unlink($transport . '/curl');
            rmdir($transport);
        }
    }

    private function elasticsearchTestTransport(): string
    {
        // The disposable harness serves HTTP. Adapt only the test transport; the production
        // entry point still requires HTTPS, and all API-key/mapping assertions hit real ES.
        $transport = sys_get_temp_dir() . '/xcrawler-transport-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($transport, 0700));
        $lookup = new Process(['sh', '-c', 'command -v curl']);
        self::assertSame(0, $lookup->run());
        $curl = trim($lookup->getOutput());
        self::assertNotSame('', $curl);
        $wrapper = <<<'BASH'
            set -euo pipefail
            args=()
            for argument in "$@"; do
              args+=("${argument/#https:\/\/elasticsearch:9200/http:\/\/elasticsearch:9200}")
            done
            exec __CURL__ "${args[@]}"
            BASH;
        file_put_contents($transport . '/curl', "#!/usr/bin/env bash\n" . str_replace('__CURL__', escapeshellarg($curl), $wrapper));
        chmod($transport . '/curl', 0700);

        return $transport;
    }

    private function postgres(string $role, string $password): PDO
    {
        return new PDO(sprintf('pgsql:host=%s;port=%s;dbname=xcrawler', config('database.connections.pgsql.host'), config('database.connections.pgsql.port')), $role, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }
}
