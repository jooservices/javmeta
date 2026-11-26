<?php

declare(strict_types=1);

namespace App\Services\Api\V2\Operations;

use App\Repositories\Api\V2\CatalogueCountsRepository;
use App\Repositories\Api\V2\SourceOpsRepository;

/** The few numbers the top bar shows to everybody, signed in or not. */
final class StatusSummary
{
    public function __construct(
        private readonly CatalogueCountsRepository $catalogue,
        private readonly SourceOpsRepository $sources,
    ) {}

    /** @return array{movies: int, performers: int, sources_ok: int, sources_enabled: int} */
    public function read(): array
    {
        $sources = $this->sources->counts();

        return [
            'movies' => $this->catalogue->movies(),
            'performers' => $this->catalogue->performers(),
            'sources_ok' => $sources['ok'],
            'sources_enabled' => $sources['enabled'],
        ];
    }
}
