<?php

declare(strict_types=1);

namespace App\Data\Api;

use JOOservices\Dto\Core\Dto;

final class PhotosDto extends Dto
{
    /**
     * @param  list<PhotoDto>  $images
     * @param  list<PhotoGalleryDto>  $galleries
     */
    public function __construct(
        public readonly array $images = [],
        public readonly array $galleries = [],
    ) {}

    /** @param array<string, mixed> $data */
    protected function beforeSerialization(array $data): array
    {
        $data['images'] = array_map(
            static fn(PhotoDto $photo): array => $photo->toArray(),
            $this->images,
        );
        $data['galleries'] = array_map(
            static fn(PhotoGalleryDto $gallery): array => $gallery->toArray(),
            $this->galleries,
        );

        return $data;
    }
}
