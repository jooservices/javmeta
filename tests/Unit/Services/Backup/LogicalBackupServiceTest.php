<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Backup;

use App\Services\Backup\LogicalBackupService;
use Carbon\Carbon;
use Illuminate\Support\Facades\File;
use Tests\Support\FakeCommandRunner;
use Tests\TestCase;

final class LogicalBackupServiceTest extends TestCase
{
    private FakeCommandRunner $runner;

    private LogicalBackupService $service;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();

        $this->runner = new FakeCommandRunner();
        $this->service = new LogicalBackupService($this->runner);
        $this->base = sys_get_temp_dir() . '/javmeta-backup-' . uniqid('', true);

        config([
            'javmeta_backup.logical_dir' => $this->base,
            'javmeta_backup.logical_keep' => 7,
            'backup.manifest_path' => $this->base . '/latest.json',
        ]);

        $this->travelTo(Carbon::parse('2026-11-03 10:00:00 UTC'));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->base);
        parent::tearDown();
    }

    public function test_a_successful_run_writes_both_dumps_and_a_readable_manifest(): void
    {
        config(['database.connections.pgsql.password' => 'sekret']);

        $result = $this->service->run();

        $this->assertTrue($result['completed']);
        $this->assertSame('2026-11-03T10:00:00+00:00', $result['created_at']);
        $this->assertSame(['postgres', 'mongo'], array_keys($result['stores']));
        $this->assertSame(64, $result['stores']['postgres']['bytes']);
        $this->assertSame(64, $result['stores']['mongo']['bytes']);

        $manifest = json_decode((string) file_get_contents($this->base . '/latest.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('2026-11-03T10:00:00+00:00', $manifest['created_at']);
        $this->assertSame('logical', $manifest['kind']);
        $this->assertSame('20261103T100000Z/postgres.dump', $manifest['stores']['postgres']['file']);
        $this->assertSame('20261103T100000Z/mongo.archive.gz', $manifest['stores']['mongo']['file']);
        $this->assertSame(hash('sha256', str_repeat('x', 64)), $manifest['stores']['postgres']['sha256']);
        $this->assertSame(hash('sha256', str_repeat('x', 64)), $manifest['stores']['mongo']['sha256']);
        $this->assertSame(0600, fileperms($this->base . '/20261103T100000Z/postgres.dump') & 0777);
        $this->assertSame(0600, fileperms($this->base . '/20261103T100000Z/mongo.archive.gz') & 0777);

        $postgres = $this->runner->commandsFor('pg_dump')[0];
        $this->assertSame('pg_dump', $postgres['command'][0]);
        $this->assertTrue(in_array('-Fc', $postgres['command'], true));
        $this->assertSame('sekret', $postgres['env']['PGPASSWORD'] ?? null);
        $this->assertNotContains('sekret', $postgres['command']);

        $this->assertNotEmpty($this->runner->commandsFor('mongodump'));
    }

    public function test_a_failed_postgres_dump_leaves_the_old_manifest_untouched_and_skips_mongo(): void
    {
        mkdir($this->base, 0777, true);
        file_put_contents($this->base . '/latest.json', json_encode(['created_at' => '2026-11-02T10:00:00+00:00'], JSON_THROW_ON_ERROR) . "\n");
        $this->runner->fail = 'pg_dump';

        $result = $this->service->run();

        $this->assertFalse($result['completed']);
        $this->assertSame('postgres', $result['failed_store']);
        $this->assertSame(1, $result['failed_exit_code']);
        $this->assertSame(['created_at' => '2026-11-02T10:00:00+00:00'], json_decode((string) file_get_contents($this->base . '/latest.json'), true, 512, JSON_THROW_ON_ERROR));
        $this->assertSame([], $this->runner->commandsFor('mongodump'));
        $this->assertSame([], File::directories($this->base));
    }

    public function test_a_mongo_failure_fails_the_run_without_writing_a_manifest(): void
    {
        mkdir($this->base, 0777, true);
        $manifest = json_encode(['created_at' => '2026-11-02T10:00:00+00:00'], JSON_THROW_ON_ERROR) . "\n";
        file_put_contents($this->base . '/latest.json', $manifest);
        config(['database.connections.mongodb.dsn' => 'mongodb://dbuser:p%40ssword@mongo:27017/javmeta']);
        $this->runner->fail = 'mongodump';

        $result = $this->service->run();

        $this->assertFalse($result['completed']);
        $this->assertSame('mongo', $result['failed_store']);
        $this->assertSame($manifest, file_get_contents($this->base . '/latest.json'));
        $this->assertNotEmpty($this->runner->commandsFor('pg_dump'));
        $this->assertNotEmpty($this->runner->commandsFor('mongodump'));
        $this->assertCount(1, $this->runner->observedConfigFiles);
        $this->assertFileDoesNotExist($this->runner->observedConfigFiles[0]['path']);
    }

    public function test_retention_keeps_only_the_newest_dump_directories(): void
    {
        mkdir($this->base, 0777, true);
        mkdir($this->base . '/20260101T000000Z');
        mkdir($this->base . '/20260102T000000Z');
        config(['javmeta_backup.logical_keep' => 2]);

        $this->service->run();

        $directories = array_map('basename', File::directories($this->base));
        sort($directories);

        $this->assertSame(['20260102T000000Z', '20261103T100000Z'], $directories);
    }

    public function test_mongodump_uses_the_configured_database_and_keeps_credentials_out_of_arguments(): void
    {
        $uri = 'mongodb://dbuser:p%40ssword@mongo:27017/auth-db';
        config([
            'database.connections.mongodb.dsn' => $uri,
            'database.connections.mongodb.database' => 'javmeta_archive',
        ]);

        $this->service->run();

        $mongo = $this->runner->commandsFor('mongodump')[0]['command'];
        $this->assertContains('--config', $mongo);
        $this->assertNotContains('--db', $mongo);
        $this->assertStringNotContainsString($uri, implode(' ', $mongo));
        $this->assertContains('--archive', $mongo);
        $this->assertContains('--gzip', $mongo);
        $this->assertCount(1, $this->runner->observedConfigFiles);
        $configFile = $this->runner->observedConfigFiles[0];
        $this->assertSame(0600, $configFile['mode']);
        $this->assertStringContainsString('mongodb://dbuser:p%40ssword@mongo:27017/javmeta_archive?authSource=auth-db', $configFile['contents']);
        $this->assertFileDoesNotExist($configFile['path']);
    }

    public function test_mongodump_preserves_an_explicit_auth_source_when_selecting_the_configured_database(): void
    {
        config([
            'database.connections.mongodb.dsn' => 'mongodb://dbuser:p%40ssword@mongo:27017/auth-db?replicaSet=rs0&authSource=admin',
            'database.connections.mongodb.database' => 'javmeta_archive',
        ]);

        $this->service->run();

        $this->assertStringContainsString(
            'mongodb://dbuser:p%40ssword@mongo:27017/javmeta_archive?replicaSet=rs0&authSource=admin',
            $this->runner->observedConfigFiles[0]['contents'],
        );
    }

    public function test_postgres_connection_url_is_parsed_without_putting_its_password_in_arguments(): void
    {
        config(['database.connections.pgsql.url' => 'postgresql://backup%40user:p%40ssword@db.internal:5544/javmeta?sslmode=require']);

        $this->service->run();

        $postgres = $this->runner->commandsFor('pg_dump')[0];
        $this->assertContains('db.internal', $postgres['command']);
        $this->assertContains('5544', $postgres['command']);
        $this->assertContains('backup@user', $postgres['command']);
        $this->assertContains('javmeta', $postgres['command']);
        $this->assertSame('p@ssword', $postgres['env']['PGPASSWORD']);
        $this->assertSame('require', $postgres['env']['PGSSLMODE']);
        $this->assertStringNotContainsString('p@ssword', implode(' ', $postgres['command']));
    }

    public function test_no_password_env_when_the_connection_has_no_password(): void
    {
        config(['database.connections.pgsql.password' => '']);

        $this->service->run();

        $postgres = $this->runner->commandsFor('pg_dump')[0];
        $this->assertArrayNotHasKey('PGPASSWORD', $postgres['env']);
    }

    public function test_an_unconfigured_directory_fails_without_running_any_dump(): void
    {
        config(['javmeta_backup.logical_dir' => '']);

        $result = $this->service->run();

        $this->assertFalse($result['completed']);
        $this->assertSame('storage', $result['failed_store']);
        $this->assertSame([], $this->runner->runs);
    }

    public function test_a_run_fails_before_dumping_when_the_manifest_path_is_empty(): void
    {
        config(['backup.manifest_path' => '']);

        $result = $this->service->run();

        $this->assertFalse($result['completed']);
        $this->assertSame('storage', $result['failed_store']);
        $this->assertSame([], $this->runner->runs);
    }
}
