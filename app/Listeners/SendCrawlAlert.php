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
        $this->alerts->sendTemplate(
            debounceKey: $event->alertKey(),
            templateKey: $event->reason === 'source_stale' ? 'crawl.source_stale' : 'source.unhealthy',
            context: array_merge(['source_slug' => $event->sourceSlug, 'reason' => $event->reason], $event->context),
            debounceSeconds: $event->reason === 'source_stale' ? (int) config('javmeta_alerts.throttle_seconds', 3600) : null,
        );
    }
}
