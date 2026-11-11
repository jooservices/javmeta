<?php

declare(strict_types=1);

namespace App\Data\Photos;

final class PhotosGalleryQuery
{
    public const MAX_PER_PAGE = 200;

    public function __construct(
        public readonly ?int $performerId,
        public readonly ?string $movieCode,
        public readonly string $source,
        public readonly string $q,
        public readonly int $page,
        public readonly int $perPage,
    ) {}
}
