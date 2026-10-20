<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\CrawlSourceUnhealthy;
use App\Services\Alerts\AlertDispatcher;

final class SendCrawlAlert
{
    public function __construct(private readonly AlertDispatcher $alerts) {}

    public function handle(CrawlSourceUnhealthy $event): void
    {
        $this->alerts->send(
            debounceKey: $event->alertKey(),
            subject: "Source unhealthy: {$event->sourceSlug}",
            body: "Reason: {$event->reason}",
            context: array_merge(['source_slug' => $event->sourceSlug], $event->context),
            debounceSeconds: $event->reason === 'source_stale' ? (int) config('javmeta_alerts.throttle_seconds', 3600) : null,
        );
    }
}
