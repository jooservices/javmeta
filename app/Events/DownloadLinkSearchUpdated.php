<?php

declare(strict_types=1);

namespace App\Events;

use App\Data\Downloads\MovieDownloadSearchData;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class DownloadLinkSearchUpdated
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly int $movieId,
        public readonly MovieDownloadSearchData $search,
        public readonly ?string $sourceSlug = null,
        public readonly int $magnetsAdded = 0,
    ) {}
}
