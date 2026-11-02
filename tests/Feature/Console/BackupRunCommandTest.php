<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Contracts\AlertNotifier;
use App\Services\Alerts\AlertDispatcher;
use App\Services\Backup\CommandRunner;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\Support\FakeCommandRunner;
use Tests\TestCase;

final class BackupRunCommandTest extends TestCase
{
    private FakeCommandRunner $runner;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();

        $this->runner = new FakeCommandRunner();
        $this->base = sys_get_temp_dir() . '/javmeta-backup-run-' . uniqid('', true);
        $this->app->instance(CommandRunner::class, $this->runner);

        config([
            'javmeta_backup.logical_dir' => $this->base,
            'backup.manifest_path' => $this->base . '/latest.json',
        ]);
        Cache::flush();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->base);
        parent::tearDown();
    }

    public function test_when_disabled_the_run_skips_the_dumps_and_exits_successfully(): void
    {
        config(['javmeta_backup.logical_enabled' => false]);

        $this->artisan('javmeta:backup-run')
            ->expectsOutputToContain('disabled')
            ->assertSuccessful();

        $this->assertSame([], $this->runner->runs);
    }

    public function test_force_does_not_override_the_disabled_setting(): void
    {
        config(['javmeta_backup.logical_enabled' => false]);

        $this->artisan('javmeta:backup-run --force')
            ->expectsOutputToContain('disabled')
            ->assertSuccessful();

        $this->assertSame([], $this->runner->runs);
    }

    public function test_an_enabled_run_writes_the_manifest_and_publishes_size_and_run_metrics(): void
    {
        $this->enableOpenObserve();
        config(['javmeta_backup.logical_enabled' => true]);

        $this->artisan('javmeta:backup-run')->assertSuccessful();

        $this->assertFileExists($this->base . '/latest.json');
        $this->assertCount(2, $this->runner->runs);

        $this->artisan('javmeta:backup-status')
            ->expectsOutputToContain('Last successful backup is current (0 seconds old).')
            ->assertSuccessful();

        Http::assertSent(function ($request): bool {
            return str_contains($request->url(), '/v1/metrics')
                && str_contains($request->body(), 'javmeta_backup_runs_total')
                && str_contains($request->body(), 'javmeta_backup_last_size_bytes')
                && str_contains($request->body(), 'postgres')
                && str_contains($request->body(), 'mongo');
        });
        $this->assertSame(1, Cache::get('javmeta:backup:runs:success'));
        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), '/v1/metrics')) {
                return false;
            }

            foreach ($request->data()['resourceMetrics'][0]['scopeMetrics'][0]['metrics'] ?? [] as $metric) {
                if (($metric['name'] ?? null) === 'javmeta_backup_runs_total') {
                    return ($metric['gauge']['dataPoints'][0]['asDouble'] ?? null) === 1.0
                        && collect($metric['gauge']['dataPoints'][0]['attributes'] ?? [])
                            ->contains(fn(array $attribute): bool => ($attribute['key'] ?? null) === 'result'
                                && ($attribute['value']['stringValue'] ?? null) === 'success');
                }
            }

            return false;
        });
    }

    public function test_a_failed_dump_keeps_the_existing_manifest_and_alerts_once(): void
    {
        $this->enableOpenObserve();
        mkdir($this->base, 0777, true);
        $manifest = json_encode(['created_at' => now()->subHours(30)->utc()->toIso8601String()], JSON_THROW_ON_ERROR) . "\n";
        file_put_contents($this->base . '/latest.json', $manifest);

        config(['javmeta_backup.logical_enabled' => true]);
        $this->runner->fail = 'pg_dump';

        $notifier = Mockery::mock(AlertNotifier::class);
        $notifier->shouldReceive('notify')
            ->once()
            ->with(
                'Database backup failed',
                Mockery::on(fn(string $body): bool => str_contains($body, 'scheduled logical database backup failed')
                    && str_contains($body, 'previous manifest was left unchanged')),
                Mockery::on(fn(array $context): bool => ($context['store'] ?? '') === 'postgres'
                    && ($context['severity'] ?? '') === 'warning'),
            );
        $this->app->instance(AlertDispatcher::class, new AlertDispatcher($notifier));

        $this->artisan('javmeta:backup-run')->assertFailed();
        $this->artisan('javmeta:backup-run')->assertFailed();

        $this->assertSame($manifest, file_get_contents($this->base . '/latest.json'));
        $this->assertSame(2, Cache::get('javmeta:backup:runs:failure'));
        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), '/v1/metrics')) {
                return false;
            }

            foreach ($request->data()['resourceMetrics'][0]['scopeMetrics'][0]['metrics'] ?? [] as $metric) {
                if (($metric['name'] ?? null) !== 'javmeta_backup_runs_total') {
                    continue;
                }

                $attributes = collect($metric['gauge']['dataPoints'][0]['attributes'] ?? []);

                return ($metric['gauge']['dataPoints'][0]['asDouble'] ?? null) === 2.0
                    && $attributes->contains(fn(array $attribute): bool => ($attribute['key'] ?? null) === 'result'
                        && ($attribute['value']['stringValue'] ?? null) === 'failure');
            }

            return false;
        });
    }

    public function test_a_run_that_is_not_due_yet_is_skipped(): void
    {
        mkdir($this->base, 0777, true);
        file_put_contents($this->base . '/latest.json', json_encode(['kind' => 'logical', 'created_at' => now()->utc()->toIso8601String()], JSON_THROW_ON_ERROR) . "\n");

        config([
            'javmeta_backup.logical_enabled' => true,
            'javmeta_backup.logical_interval_hours' => 24,
        ]);

        $this->artisan('javmeta:backup-run')
            ->expectsOutputToContain('not due')
            ->assertSuccessful();

        $this->assertSame([], $this->runner->runs);
    }

    public function test_a_recent_non_logical_manifest_does_not_delay_the_first_logical_run(): void
    {
        mkdir($this->base, 0777, true);
        file_put_contents($this->base . '/latest.json', json_encode([
            'kind' => 'daily',
            'created_at' => now()->utc()->toIso8601String(),
        ], JSON_THROW_ON_ERROR) . "\n");
        config(['javmeta_backup.logical_enabled' => true]);

        $this->artisan('javmeta:backup-run')->assertSuccessful();

        $this->assertCount(2, $this->runner->runs);
        $manifest = json_decode((string) file_get_contents($this->base . '/latest.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('logical', $manifest['kind']);
    }

    private function enableOpenObserve(): void
    {
        config([
            'openobserve.enabled' => true,
            'openobserve.url' => 'http://openobserve.test',
            'openobserve.org' => 'javmeta_it',
            'openobserve.email' => 'backup-test@example.test',
            'openobserve.password' => 'test-only',
            'openobserve.timeout' => 1,
        ]);
        Http::fake(['openobserve.test/*' => Http::response(['code' => 200], 200)]);
    }
}
