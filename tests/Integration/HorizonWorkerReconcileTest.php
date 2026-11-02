<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\CrawlEvent;
use App\Services\Settings\SettingsService;
use App\Services\Workers\HorizonLayoutSnapshot;
use App\Services\Workers\HorizonWorkerLayout;
use App\Services\Workers\HorizonWorkerReconciler;
use App\Services\Workers\ReconcileHorizonScale;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;
use JOOservices\LaravelConfig\Contracts\ConfigStore;
use Laravel\Horizon\Contracts\HorizonCommandQueue;
use Laravel\Horizon\Contracts\SupervisorRepository;
use Laravel\Horizon\Events\MasterSupervisorLooped;
use Laravel\Horizon\MasterSupervisor;
use Mockery;

final class HorizonWorkerReconcileTest extends IntegrationTestCase
{
    private ConfigStore $store;

    private HorizonCommandQueue $commands;

    private string $redisConnection;

    /** @var list<array{node: string, master: string}> */
    private array $nodes = [];

    protected function setUp(): void
    {
        parent::setUp();
        SettingsService::forgetDefaults();
        $this->app->forgetInstance(SettingsService::class);
        $this->store = app(ConfigStore::class);
        $this->commands = app(HorizonCommandQueue::class);
        $this->redisConnection = (string) config('horizon.use', 'default');
        config([
            'javmeta_workers.role' => 'worker',
            'javmeta_workers.reconcile_jitter_seconds' => 0,
            'javmeta_workers.reconcile_cooldown_seconds' => 600,
            'javmeta_queues.workers_per_queue' => 2,
            'javmeta_queues.browser_workers_per_queue' => 2,
        ]);
        $this->app->instance(SupervisorRepository::class, $this->supervisorRepository());
    }

    protected function tearDown(): void
    {
        foreach ($this->nodes as $node) {
            $this->clearNode($node['node'], $node['master']);
        }
        $this->store->forget('settings.queue.workers_per_queue');
        $this->store->forget('settings.worker.browser_workers_per_queue');
        SettingsService::forgetDefaults();
        parent::tearDown();
    }

    public function test_database_worker_count_is_applied_by_horizon_scale_without_restarting_the_master(): void
    {
        $node = 'joo-xcr-301-db-change';
        $master = 'worker-joo-xcr-301-db-change-master';
        $running = $this->seedSnapshot($node, $master);
        SettingsService::forgetDefaults();
        $settings = app(SettingsService::class);
        $settings->set('queue.workers_per_queue', 4);
        Log::shouldReceive('info')->once()->withArgs(static function (string $message, array $context): bool {
            return $message === 'Horizon worker counts reconciled.'
                && ($context['workers']['listing'] ?? null) === 4;
        })->andReturnNull();

        $result = app(HorizonWorkerReconciler::class)->reconcile();

        self::assertSame(4, $this->store->get('settings.queue.workers_per_queue'));
        self::assertSame('scaled', $result['status'], 'a worker count change must not terminate or restart Horizon');
        self::assertSame(4, $result['new']['supervisors']['crawler-listing']['maxProcesses']);
        self::assertSame(2, $running['supervisors']['crawler-listing']['maxProcesses']);
        self::assertDatabaseHas('crawl_events', [
            'source_slug' => 'worker-reconcile',
            'kind' => CrawlEvent::KIND_WORKERS_RECONFIGURED,
        ]);

        $commands = $this->commands->pending($master . ':crawler-listing');
        self::assertCount(1, $commands);
        self::assertSame(ReconcileHorizonScale::class, $commands[0]->command);
        self::assertSame(['scale' => 4], $commands[0]->options);
        $this->publishAppliedSnapshot($master, $result['new']['supervisors']);
        self::assertSame('current', app(HorizonWorkerReconciler::class)->reconcile()['status']);
    }

    public function test_database_count_of_one_is_scaled_to_every_queue_and_never_to_zero(): void
    {
        $node = 'joo-xcr-301-floor';
        $master = 'worker-joo-xcr-301-floor-master';
        $running = $this->seedSnapshot($node, $master);
        SettingsService::forgetDefaults();
        $settings = app(SettingsService::class);
        $settings->set('queue.workers_per_queue', 1);
        $settings->set('worker.browser_workers_per_queue', 1);

        $result = app(HorizonWorkerReconciler::class)->reconcile();

        self::assertSame('scaled', $result['status']);
        self::assertSame([1], array_values(array_unique(HorizonWorkerLayout::workerCounts($result['new']['supervisors']))));
        foreach (array_keys($result['new']['supervisors']) as $name) {
            $commands = $this->commands->pending($master . ':' . $name);
            if ($running['supervisors'][$name]['maxProcesses'] === $result['new']['supervisors'][$name]['maxProcesses']) {
                self::assertCount(0, $commands, $name);

                continue;
            }
            self::assertCount(1, $commands, $name);
            self::assertSame(['scale' => 1], $commands[0]->options, $name);
        }
        $fleetLock = json_decode((string) Redis::connection($this->redisConnection)->get(HorizonLayoutSnapshot::FLEET_RECONCILE_LOCK), true);
        self::assertGreaterThanOrEqual(now()->timestamp + 200, (int) ($fleetLock['release_after'] ?? 0));
        $this->publishAppliedSnapshot($master, $result['new']['supervisors']);
        self::assertNotFalse(Redis::connection($this->redisConnection)->get(HorizonLayoutSnapshot::FLEET_RECONCILE_LOCK));
        $this->travel(201)->seconds();
        $this->publishAppliedSnapshot($master, $result['new']['supervisors']);
        self::assertSame(0, Redis::connection($this->redisConnection)->exists(HorizonLayoutSnapshot::FLEET_RECONCILE_LOCK));
    }

    public function test_invalid_database_count_keeps_the_last_good_layout_without_stopping_workers(): void
    {
        $node = 'joo-xcr-301-invalid';
        $master = 'worker-joo-xcr-301-invalid-master';
        SettingsService::forgetDefaults();
        app(SettingsService::class)->set('queue.workers_per_queue', 4);
        $running = $this->seedSnapshot($node, $master);
        $this->store->set('settings.queue.workers_per_queue', 0, 'int');
        SettingsService::forgetDefaults();
        config(['javmeta_queues.workers_per_queue' => 2]);

        $result = app(HorizonWorkerReconciler::class)->reconcile();
        $current = json_decode(
            (string) Redis::connection($this->redisConnection)->get(HorizonLayoutSnapshot::key((string) $running['node'])),
            true,
        );

        self::assertSame('invalid-settings', $result['status']);
        self::assertSame($running['hash'], $current['snapshot']['hash']);
        self::assertSame(4, $running['supervisors']['crawler-listing']['maxProcesses']);
        self::assertSame(4, $current['snapshot']['supervisors']['crawler-listing']['maxProcesses']);
        self::assertSame([], $this->commands->pending($master . ':crawler-listing'));
    }

    public function test_snapshot_event_resolves_its_repository_and_reports_unobserved_supervisors_as_zero(): void
    {
        $node = 'joo-xcr-301-snapshot';
        $master = 'worker-joo-xcr-301-snapshot-master';
        $running = $this->seedSnapshot($node, $master);
        $repository = Mockery::mock(SupervisorRepository::class);
        $repository->shouldReceive('all')->once()->andReturn([(object) [
            'name' => $master . ':crawler-listing',
            'master' => $master,
            'processes' => ['redis:listing' => 2],
        ]]);
        $this->app->instance(SupervisorRepository::class, $repository);
        $masterSupervisor = new MasterSupervisor();
        $masterSupervisor->name = $master;

        app(HorizonLayoutSnapshot::class)->handle(new MasterSupervisorLooped($masterSupervisor));

        $payload = json_decode(
            (string) Redis::connection($this->redisConnection)->get(HorizonLayoutSnapshot::key((string) $running['node'])),
            true,
        );
        $snapshot = $payload['snapshot'];
        self::assertSame(2, $snapshot['supervisors']['crawler-listing']['maxProcesses']);
        self::assertSame(0, $snapshot['supervisors']['crawler-detail']['maxProcesses']);
        self::assertNotSame($running['hash'], $snapshot['hash']);
    }

    public function test_fleet_lock_remains_after_a_scale_command_was_queued_before_a_later_failure(): void
    {
        $node = 'joo-xcr-301-partial-scale';
        $master = 'worker-joo-xcr-301-partial-scale-master';
        $this->seedSnapshot($node, $master);
        SettingsService::forgetDefaults();
        app(SettingsService::class)->set('queue.workers_per_queue', 4);
        $queued = 0;
        $commands = Mockery::mock(HorizonCommandQueue::class);
        $commands->shouldReceive('push')->twice()->andReturnUsing(static function () use (&$queued): void {
            $queued++;
            if ($queued === 2) {
                throw new \RuntimeException('Second scale command failed.');
            }
        });
        $this->app->instance(HorizonCommandQueue::class, $commands);

        try {
            app(HorizonWorkerReconciler::class)->reconcile();
            self::fail('Expected the second queued scale command to fail.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Second scale command failed.', $exception->getMessage());
        }

        self::assertSame(2, $queued);
        self::assertNotFalse(Redis::connection($this->redisConnection)->get(HorizonLayoutSnapshot::FLEET_RECONCILE_LOCK));
    }

    public function test_node_jitter_and_fleet_lock_serialize_worker_changes(): void
    {
        $hostA = $this->hostWithPositiveJitter();
        $nodeA = 'worker-' . str_replace('.', '-', $hostA);
        $masterA = $nodeA . '-master';
        $hostB = 'joo-xcr-301-jitter-peer';
        $nodeB = 'worker-' . str_replace('.', '-', $hostB);
        $masterB = $nodeB . '-master';
        $this->seedSnapshot($hostA, $masterA);
        $this->seedSnapshot($hostB, $masterB);
        config(['javmeta_workers.reconcile_jitter_seconds' => 60]);
        SettingsService::forgetDefaults();
        app(SettingsService::class)->set('queue.workers_per_queue', 4);
        $delay = hexdec(substr(hash('sha256', $nodeA), 0, 8)) % 61;
        $this->assertGreaterThan(0, $delay);

        config(['sentry.node' => $hostA]);
        $this->travel(120)->seconds();
        $reconciler = app(HorizonWorkerReconciler::class);
        self::assertSame('jitter', $reconciler->reconcile()['status']);
        self::assertSame([], $this->commands->pending($masterA . ':crawler-listing'));
        $this->travel($delay)->seconds();
        self::assertSame('scaled', $reconciler->reconcile()['status']);

        config(['sentry.node' => $hostB, 'javmeta_workers.reconcile_jitter_seconds' => 0]);
        self::assertSame('fleet-locked', $reconciler->reconcile()['status']);
        self::assertSame([], $this->commands->pending($masterB . ':crawler-listing'));
    }

    /** @return array<string, mixed> */
    private function seedSnapshot(string $host, string $master): array
    {
        config(['sentry.node' => $host]);
        $snapshot = app(HorizonWorkerLayout::class)->snapshot();
        $this->clearNode((string) $snapshot['node'], $master);
        $snapshot['master'] = $master;
        config(['javmeta_workers.booted_layout' => $snapshot]);
        Redis::connection($this->redisConnection)->setex(
            HorizonLayoutSnapshot::key((string) $snapshot['node']),
            180,
            json_encode(['snapshot' => $snapshot, 'pid' => 42], JSON_THROW_ON_ERROR),
        );
        $this->nodes[] = ['node' => (string) $snapshot['node'], 'master' => $master];

        return $snapshot;
    }

    private function clearNode(string $node, string $master): void
    {
        Redis::connection($this->redisConnection)->del([
            HorizonLayoutSnapshot::key($node),
            'javmeta:horizon:reconcile:lock:' . $node,
            'javmeta:horizon:reconcile:cooldown:' . $node,
            'javmeta:horizon:reconcile:mismatch:' . $node,
            HorizonLayoutSnapshot::FLEET_RECONCILE_LOCK,
        ]);
        Redis::connection($this->redisConnection)->srem(HorizonLayoutSnapshot::NODE_REGISTRY_KEY, $node);
        foreach (array_keys((new HorizonWorkerLayout())->supervisors()) as $name) {
            $this->commands->flush($master . ':' . $name);
        }
    }

    /** @param array<string, array<string, mixed>> $supervisors */
    private function publishAppliedSnapshot(string $master, array $supervisors): void
    {
        $active = [];
        foreach ($supervisors as $name => $supervisor) {
            $queue = (string) $supervisor['queue'][0];
            $active[] = (object) [
                'name' => $master . ':' . $name,
                'master' => $master,
                'processes' => ['redis:' . $queue => (int) $supervisor['maxProcesses']],
            ];
        }
        $repository = Mockery::mock(SupervisorRepository::class);
        $repository->shouldReceive('all')->once()->andReturn($active);
        $this->app->instance(SupervisorRepository::class, $repository);
        $masterSupervisor = new MasterSupervisor();
        $masterSupervisor->name = $master;
        app(HorizonLayoutSnapshot::class)->handle(new MasterSupervisorLooped($masterSupervisor), $repository);
    }

    private function supervisorRepository(): SupervisorRepository
    {
        $repository = Mockery::mock(SupervisorRepository::class);
        $repository->shouldReceive('find')->andReturnUsing(static function (string $name): object {
            [$master] = explode(':', $name, 2);

            return (object) ['name' => $name, 'master' => $master, 'status' => 'running'];
        });
        $repository->shouldReceive('all')->andReturn([]);

        return $repository;
    }

    private function hostWithPositiveJitter(): string
    {
        foreach (['joo-xcr-301-jitter-a', 'joo-xcr-301-jitter-b', 'joo-xcr-301-jitter-c'] as $host) {
            $node = 'worker-' . str_replace('.', '-', $host);
            if (hexdec(substr(hash('sha256', $node), 0, 8)) % 61 > 0) {
                return $host;
            }
        }

        self::fail('Expected a deterministic non-zero node jitter.');
    }
}
