<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Data\Crawl\MovieDraft;
use App\Services\Crawl\MovieDraftSink;

final class FakeMovieDraftSink implements MovieDraftSink
{
    /** @var list<MovieDraft> */
    public array $drafts = [];

    /** @var list<bool> */
    public array $recrawlFlags = [];

    public function accept(MovieDraft $draft, bool $isRecrawl = false): void
    {
        $this->drafts[] = $draft;
        $this->recrawlFlags[] = $isRecrawl;
    }
}
