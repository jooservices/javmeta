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

        $this->alerts->sendTemplate(
            debounceKey: "miss:{$event->entity}:" . strtolower($event->query),
            templateKey: 'catalog.lookup_missing',
            context: array_merge([
                'entity_type' => $event->entity,
                'entity_key' => $event->query,
            ], $event->context),
            debounceSeconds: $ttl,
        );
    }
}
