<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\WorkerHeartbeatStale;
use App\Services\Alerts\AlertDispatcher;

final class SendWorkerHeartbeatAlert
{
    public function __construct(private readonly AlertDispatcher $alerts) {}

    public function handle(WorkerHeartbeatStale $event): void
    {
        $this->alerts->sendTemplate(
            debounceKey: 'watchdog:worker_stale',
            templateKey: 'crawl.heartbeat_stale',
            context: $event->context,
            debounceSeconds: (int) config('javmeta_alerts.throttle_seconds', 3600),
        );
    }
}
