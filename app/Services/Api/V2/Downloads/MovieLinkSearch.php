<?php

declare(strict_types=1);

namespace App\Services\Api\V2\Downloads;

use App\Services\Downloads\DownloadLinkSearchService;

/** The link search of the crawler (rate budgets, source queue, cooldown) seen through the narrow interface the API needs. */
final class MovieLinkSearch implements LinkSearch
{
    public function __construct(private readonly DownloadLinkSearchService $search) {}

    public function start(string $movieCode, string $clientKey): bool
    {
        return $this->search->start($movieCode, $clientKey)->queued;
    }
}
