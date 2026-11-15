<?php

declare(strict_types=1);

namespace App\Data\Photos;

final class PhotosGroupQuery
{
    public const BY = ['performer', 'movie', 'gallery'];

    public const MAX_PER_PAGE = 200;

    public function __construct(
        public readonly string $by,
        public readonly string $q,
        public readonly string $source,
        public readonly ?int $performerId,
        public readonly ?string $movieCode,
        public readonly int $page,
        public readonly int $perPage,
    ) {}
}
