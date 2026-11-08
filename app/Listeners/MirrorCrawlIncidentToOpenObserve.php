<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\CrawlIncidentOccurred;
use App\Observability\ObservabilityEmitter;

final class MirrorCrawlIncidentToOpenObserve
{
    public function __construct(private readonly ObservabilityEmitter $observability) {}

    public function handle(CrawlIncidentOccurred $event): void
    {
        $this->observability->emitCrawlEvent($event->sourceSlug, $event->kind, $event->detail + ['page_url' => $event->url]);
    }
}
