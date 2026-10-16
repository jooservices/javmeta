<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\ConsumerMissedLookup;
use App\Services\Alerts\AlertDispatcher;

/**
 * Notifies ops when a consumer lookup/search misses so the title/performer can be crawled ASAP.
 */
final class SendMissedLookupAlert
{
    public function __construct(private readonly AlertDispatcher $alerts) {}

    public function handle(ConsumerMissedLookup $event): void
    {
        $ttl = (int) config('javmeta_alerts.miss_debounce_seconds', 3600);

        $this->alerts->send(
            debounceKey: "miss:{$event->entity}:" . strtolower($event->query),
            subject: "Consumer miss: {$event->entity}",
            body: 'Requested ' . $event->entity . ' not in DB — fetch ASAP.',
            context: array_merge([
                'entity' => $event->entity,
                'query' => $event->query,
            ], $event->context),
            debounceSeconds: $ttl,
        );
    }
}
