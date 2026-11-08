<?php

declare(strict_types=1);

namespace App\Services\Crawl;

use App\Data\Crawl\MovieDraft;

/**
 * Persistence seam for the crawl pipeline. JV-W1-007's MoviePersister
 * implements this contract and binds it in the container; FetchDetailJob
 * resolves the sink from the container and falls back to dispatching the
 * MovieDraftCaptured event only when no sink is bound.
 */
interface MovieDraftSink
{
    public function accept(MovieDraft $draft, bool $isRecrawl = false): void;
}
