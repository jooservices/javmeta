<?php

declare(strict_types=1);

namespace Tests\Unit\Providers;

use App\Providers\HorizonServiceProvider;
use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Horizon\Events\MasterSupervisorLooped;
use Laravel\Horizon\MasterSupervisor;
use Mockery;
use Tests\TestCase;

final class HorizonServiceProviderTest extends TestCase
{
    public function test_horizon_supervisors_cover_worker_and_observability_queues_in_all_environments(): void
    {
        $names = ['listing', 'detail', 'performer_listing', 'performer_detail', 'gallery', 'search'];
        $names = [...$names, 'browser_listing', 'browser_detail', 'browser_performer_listing', 'browser_performer_detail', 'browser_gallery'];
        $names = [...$names, 'retry_listing', 'retry_detail', 'retry_performer_listing', 'retry_performer_detail', 'retry_gallery'];
        $horizonNames = [...$names, 'observability'];
        $this->assertSame($horizonNames, config('javmeta_queues.horizon_names'));
        $this->assertSame($names, config('javmeta_queues.feed_pool_names'));
        $expectedSupervisorNames = array_map(static fn(string $name): string => 'crawler-' . $name, $names);
        $expectedSupervisorNames[] = 'api-observability';

        foreach (['production', 'local', 'testing'] as $environment) {
            $supervisors = config("horizon.environments.{$environment}");
            $this->assertSame($expectedSupervisorNames, array_keys($supervisors));

            foreach ($supervisors as $name => $supervisor) {
                $this->assertSame('redis', $supervisor['connection']);
                $expectedWorkers = str_contains($name, 'browser_')
                    ? config('javmeta_queues.browser_workers_per_queue')
                    : (str_contains($name, 'retry_') ? config('javmeta_queues.retry.workers_per_queue') : config('javmeta_queues.workers_per_queue'));
                $this->assertSame($expectedWorkers, $supervisor['maxProcesses']);
                $this->assertSame(180, $supervisor['timeout']);
                $queue = $name === 'api-observability' ? 'observability' : substr($name, strlen('crawler-'));
                $this->assertSame([$queue], $supervisor['queue']);
            }
        }
    }

    public function test_worker_timeout_is_shorter_than_redis_retry_after(): void
    {
        $this->assertLessThan(
            config('queue.connections.redis.retry_after'),
            config('javmeta_queues.worker_timeout'),
        );
    }

    public function test_horizon_waits_for_running_jobs_when_terminating(): void
    {
        $this->assertFalse(config('horizon.fast_termination'));
    }

    public function test_outside_the_test_suite_boot_applies_settings_names_the_master_and_listens_for_layout_snapshots(): void
    {
        config(['app.key' => 'base64:' . base64_encode(random_bytes(32)), 'sentry.node' => 'worker-boot-test']);
        $events = $this->app->make(Dispatcher::class);
        $app = Mockery::mock($this->app)->makePartial();
        $app->shouldReceive('runningUnitTests')->andReturn(false);

        try {
            (new HorizonServiceProvider($app))->boot();

            self::assertTrue($events->hasListeners(MasterSupervisorLooped::class));
            self::assertNotNull(config('javmeta_workers.booted_layout'), 'The booted layout is remembered for later reconciliation.');
            self::assertMatchesRegularExpression('/^javmeta-horizon-main-worker-boot-test-[A-Za-z0-9]{4}$/', MasterSupervisor::name());
        } finally {
            // determineNameUsing() is process-wide; put the default back so other tests are not renamed.
            (new \ReflectionProperty(MasterSupervisor::class, 'nameResolver'))->setValue(null, null);
        }
    }
}
