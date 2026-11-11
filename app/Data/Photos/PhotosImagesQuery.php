<?php

declare(strict_types=1);

namespace App\Data\Photos;

final class PhotosImagesQuery
{
    public const DEFAULT_PER_PAGE = 40;

    public const MAX_PER_PAGE = 200;

    public function __construct(
        public readonly string $galleryId,
        public readonly int $page,
        public readonly int $perPage,
    ) {}
}
