<?php

declare(strict_types=1);

namespace Tests\E2E\Support;

final readonly class CatalogSeed
{
    /** @param list<string> $movieCodes @param list<string> $paginationCodes @param list<string> $filteredCodes */
    public function __construct(
        public string $primaryCode,
        public array $movieCodes,
        public array $paginationCodes,
        public array $filteredCodes,
        public int $performerId,
        public string $performerUuid,
        public string $performerRomaji,
        public string $performerKanji,
        public string $performerAlias,
        public string $genre,
    ) {}
}
