<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Services\Health\SchedulerHeartbeat;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class DeploymentRuntimeTest extends TestCase
{
    public function test_a_started_scheduler_needs_a_fresh_heartbeat_but_workers_do_not(): void
    {
        require_once base_path('deploy/lib/readiness.php');
        $heartbeat = app(SchedulerHeartbeat::class);
        Cache::forget(SchedulerHeartbeat::CACHE_KEY);
        $masters = [(object) ['status' => 'running']];
        $this->assertTrue(\JavMeta\Deploy\runtimeReady($masters, false, $heartbeat->isFresh()));
        $this->assertFalse(\JavMeta\Deploy\runtimeReady($masters, true, $heartbeat->isFresh()));
        $heartbeat->beat();
        $this->assertTrue(\JavMeta\Deploy\runtimeReady($masters, true, $heartbeat->isFresh()));
        $this->travel(SchedulerHeartbeat::STALE_AFTER_SECONDS + 1)->seconds();
        $this->assertFalse(\JavMeta\Deploy\runtimeReady($masters, true, $heartbeat->isFresh()));
    }
}
