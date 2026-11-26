<?php

declare(strict_types=1);

namespace App\Services\Api\V2\Catalogue;

use App\Http\Api\V2\Envelope;
use App\Repositories\Api\V2\PhotoCollectionRepository;

/** The photo collection: movie covers or performer avatars, as thumbnail and full-size links with a label. */
final class PhotoCollection
{
    private const MAX_PER_PAGE = 120;

    public function __construct(private readonly PhotoCollectionRepository $repository) {}

    /**
     * @param array<string, mixed> $query
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function page(array $query): array
    {
        [$page, $perPage] = Paging::from($query, 48, self::MAX_PER_PAGE);
        $term = (string) ($query['q'] ?? '');
        $offset = ($page - 1) * $perPage;

        if (($query['kind'] ?? 'avatar') === 'cover') {
            $result = $this->repository->covers($term, $perPage, $offset);
            $data = array_map(static fn(object $r): array => ['kind' => 'cover', 'thumb_url' => $r->cover_thumb_url ?: $r->cover_url, 'full_url' => $r->cover_url, 'label' => $r->title_en ?: ($r->title_jp ?: $r->code), 'movie_code' => $r->code], $result['rows']);
        } else {
            $result = $this->repository->avatars($term, $perPage, $offset);
            $data = array_map(static fn(object $r): array => ['kind' => 'avatar', 'thumb_url' => $r->photo_url, 'full_url' => $r->photo_url, 'label' => $r->name_romaji, 'performer_id' => (int) $r->id], $result['rows']);
        }

        return ['data' => $data, 'meta' => Envelope::pagination($page, $perPage, $result['total'])];
    }
}
