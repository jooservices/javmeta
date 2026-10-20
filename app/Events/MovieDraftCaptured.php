<?php

declare(strict_types=1);

namespace App\Events;

use App\Data\Crawl\MovieDraft;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Marker event emitted by FetchDetailJob when no MovieDraftSink is bound yet
 * (persistence lands in JV-W1-007). Carries the normalized draft so a
 * listener can capture it without writing movies.
 */
final class MovieDraftCaptured
{
    use Dispatchable;

    public function __construct(public readonly MovieDraft $draft, public readonly bool $isRecrawl = false) {}
}
