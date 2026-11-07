<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands;

use App\Services\Settings\SettingsService;
use App\Services\Workers\HorizonLayoutSnapshot;
use App\Services\Workers\HorizonWorkerLayout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use JOOservices\LaravelConfig\Contracts\ConfigStore;
use JOOservices\LaravelConfig\Testing\FakeConfigStore;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/** Gap coverage for horizon:reconcile-workers (no Redis: the connection is a Mockery double). */
final class HorizonWorkerCommandsTest extends TestCase
{
    use RefreshDatabase;

    private FakeConfigStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        SettingsService::forgetDefaults();
        $this->store = new FakeConfigStore();
        $this->app->instance(ConfigStore::class, $this->store);
        $this->app->forgetInstance(SettingsService::class);
        config(['javmeta_workers.role' => 'worker', 'horizon.use' => 'default']);
    }

    protected function tearDown(): void
    {
        SettingsService::forgetDefaults();

        parent::tearDown();
    }

    public function test_it_keeps_the_layout_when_the_settings_store_is_unavailable(): void
    {
        $store = Mockery::mock(ConfigStore::class);
        $store->shouldReceive('group')->andThrow(new RuntimeException('settings store is down'));
        $this->app->instance(ConfigStore::class, $store);
        $this->app->forgetInstance(SettingsService::class);

        $this->artisan('horizon:reconcile-workers')
            ->expectsOutputToContain('Worker reconciliation: settings are invalid or unavailable; keeping the current layout.')
            ->assertSuccessful();
    }

    public function test_it_keeps_the_layout_when_a_stored_worker_setting_is_invalid(): void
    {
        $this->store->set('settings.queue.workers_per_queue', 0, 'int');

        $this->artisan('horizon:reconcile-workers')
            ->expectsOutputToContain('Worker reconciliation: settings are invalid or unavailable; keeping the current layout.')
            ->assertSuccessful();
    }

    public function test_it_reports_when_no_horizon_master_is_running(): void
    {
        $redis = Mockery::mock();
        $redis->shouldReceive('get')->andReturn(null);
        Redis::shouldReceive('connection')->andReturn($redis);

        $this->artisan('horizon:reconcile-workers')
            ->expectsOutputToContain('Worker reconciliation: no-running-master.')
            ->assertSuccessful();
    }

    public function test_it_reports_when_the_running_layout_already_matches(): void
    {
        $desired = app(HorizonWorkerLayout::class)->snapshot();
        $redis = Mockery::mock();
        $redis->shouldReceive('get')->andReturn(json_encode(['snapshot' => $desired], JSON_THROW_ON_ERROR));
        $redis->shouldReceive('del')->once();
        Redis::shouldReceive('connection')->andReturn($redis);

        $this->artisan('horizon:reconcile-workers')
            ->expectsOutputToContain('Worker reconciliation: current.')
            ->assertSuccessful();
    }

    public function test_it_asks_for_a_manual_restart_when_more_than_worker_counts_changed(): void
    {
        Log::spy();
        $desired = app(HorizonWorkerLayout::class)->snapshot();
        $running = ['node' => 'another-node', 'role' => 'worker', 'supervisors' => [], 'hash' => 'old-layout', 'master' => 'master-1'];
        $redis = Mockery::mock();
        $redis->shouldReceive('get')->andReturn(json_encode(['snapshot' => $running], JSON_THROW_ON_ERROR));
        Redis::shouldReceive('connection')->andReturn($redis);

        $this->artisan('horizon:reconcile-workers')
            ->expectsOutputToContain('Worker reconciliation: manual Horizon restart required.')
            ->assertSuccessful();

        self::assertNotSame($running['hash'], $desired['hash']);
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_the_snapshot_key_is_scoped_to_the_node(): void
    {
        self::assertSame('javmeta:horizon:worker-layout:node-1', HorizonLayoutSnapshot::key('node-1'));
    }
}
