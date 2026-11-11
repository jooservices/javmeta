<?php

declare(strict_types=1);

namespace App\Services\Performers;

use App\Data\Api\PerformerPhotoPageItemDto;
use App\Repositories\PerformerRepository;

final class PerformerPhotosService
{
    public function __construct(private readonly PerformerRepository $performers) {}

    /** @return array{items: list<PerformerPhotoPageItemDto>, total: int, page: int, per_page: int, last_page: int, has_more: bool}|null */
    public function page(string|int $performerId, string $kind, int $perPage, int $page): ?array
    {
        $performer = $this->performers->findForDisplay($performerId);
        if ($performer === null) {
            return null;
        }

        return $this->performers->photosPage((int) $performer->id, $kind, $perPage, $page);
    }
}
