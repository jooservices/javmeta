<?php

declare(strict_types=1);

namespace Tests\Unit\Providers;

use Tests\TestCase;

final class HorizonServiceProviderTest extends TestCase
{
    public function test_horizon_supervisors_cover_all_six_queues_in_all_environments(): void
    {
        $names = ['listing', 'detail', 'performer_listing', 'performer_detail', 'gallery', 'search'];
        $this->assertSame($names, config('javmeta_queues.horizon_names'));
        $this->assertSame($names, config('javmeta_queues.feed_pool_names'));
        $expectedSupervisorNames = array_map(static fn(string $name): string => 'crawler-' . $name, $names);

        foreach (['production', 'local', 'testing'] as $environment) {
            $supervisors = config("horizon.environments.{$environment}");
            $this->assertSame($expectedSupervisorNames, array_keys($supervisors));

            foreach ($supervisors as $supervisor) {
                $this->assertSame('redis', $supervisor['connection']);
                $this->assertSame(1, $supervisor['maxProcesses']);
                $this->assertSame(180, $supervisor['timeout']);
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
}
