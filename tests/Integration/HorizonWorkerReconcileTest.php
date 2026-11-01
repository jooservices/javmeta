<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\CrawlEvent;
use App\Services\Settings\SettingsService;
use App\Services\Workers\HorizonLayoutSnapshot;
use App\Services\Workers\HorizonWorkerLayout;
use App\Services\Workers\HorizonWorkerReconciler;
use Illuminate\Support\Facades\Redis;
use JOOservices\LaravelConfig\Contracts\ConfigStore;
use JOOservices\LaravelConfig\Testing\FakeConfigStore;

final class HorizonWorkerReconcileTest extends IntegrationTestCase
{
    private string $node;

    protected function setUp(): void
    {
        parent::setUp();
        SettingsService::forgetDefaults();
        $this->node = 'worker-joo-xcr-293-integration';
        config([
            'javmeta_workers.role' => 'worker',
            'sentry.node' => 'joo-xcr-293-integration',
            'javmeta_workers.reconcile_jitter_seconds' => 0,
            'javmeta_workers.reconcile_cooldown_seconds' => 600,
            'javmeta_queues.workers_per_queue' => 1,
        ]);
        $this->app->instance(ConfigStore::class, new FakeConfigStore());
        $this->app->forgetInstance(SettingsService::class);
        Redis::connection()->del([
            HorizonLayoutSnapshot::key($this->node),
            HorizonLayoutSnapshot::FLEET_RESTART_LOCK,
            'javmeta:horizon:reconcile:lock:' . $this->node,
            'javmeta:horizon:reconcile:cooldown:' . $this->node,
            'javmeta:horizon:reconcile:mismatch:' . $this->node,
        ]);
    }

    protected function tearDown(): void
    {
        Redis::connection()->del([
            HorizonLayoutSnapshot::key($this->node),
            HorizonLayoutSnapshot::FLEET_RESTART_LOCK,
            'javmeta:horizon:reconcile:lock:' . $this->node,
            'javmeta:horizon:reconcile:cooldown:' . $this->node,
            'javmeta:horizon:reconcile:mismatch:' . $this->node,
        ]);
        SettingsService::forgetDefaults();
        parent::tearDown();
    }

    public function test_real_redis_hash_cooldown_and_deploy_lock_guard_reconciliation(): void
    {
        $layout = app(HorizonWorkerLayout::class);
        $running = $layout->snapshot();
        Redis::connection()->setex(HorizonLayoutSnapshot::key((string) $running['node']), 180, json_encode(['snapshot' => $running, 'pid' => 42], JSON_THROW_ON_ERROR));
        config(['javmeta_queues.workers_per_queue' => 4]);
        $terminations = 0;
        $reconciler = app(HorizonWorkerReconciler::class);

        $first = $reconciler->reconcile(static function () use (&$terminations): void {
            $terminations++;
        });
        self::assertSame('terminated', $first['status']);
        self::assertSame(1, $terminations);
        self::assertDatabaseHas('crawl_events', [
            'source_slug' => 'worker-reconcile',
            'kind' => CrawlEvent::KIND_WORKERS_RECONFIGURED,
        ]);

        self::assertSame('cooldown', $reconciler->reconcile(static function () use (&$terminations): void {
            $terminations++;
        })['status']);
        self::assertSame(1, $terminations);

        Redis::connection()->del('javmeta:horizon:reconcile:cooldown:' . (string) $running['node']);
        $sharedPath = base_path('shared');
        $createdDirectory = ! is_dir($sharedPath);
        if ($createdDirectory) {
            mkdir($sharedPath, 0700);
        }
        $lockPath = $sharedPath . '/deploy.lock';
        self::assertFileDoesNotExist($lockPath);
        $handle = fopen($lockPath, 'x');
        self::assertIsResource($handle);
        flock($handle, LOCK_EX);
        try {
            self::assertSame('deploy-locked', $reconciler->reconcile(static function () use (&$terminations): void {
                $terminations++;
            })['status']);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
            unlink($lockPath);
            if ($createdDirectory) {
                rmdir($sharedPath);
            }
        }
        self::assertSame(1, $terminations);
    }
}
