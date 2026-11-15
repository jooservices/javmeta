<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\DownloadLinkSearchUpdated;
use App\Observability\ObservabilityEmitter;

/**
 * Mirrors download-link search transitions into ops observability (same
 * subscriber pattern as crawl incident listeners).
 */
final class RecordDownloadLinkSearchUpdate
{
    public function __construct(private readonly ObservabilityEmitter $observability) {}

    public function handle(DownloadLinkSearchUpdated $event): void
    {
        $this->observability->emitOps('download_link_search', [
            'movie_id' => $event->movieId,
            'state' => $event->search->state,
            'found_count' => $event->search->found_count,
            'source_slug' => $event->sourceSlug,
            'magnets_added' => $event->magnetsAdded,
        ]);
    }
}
