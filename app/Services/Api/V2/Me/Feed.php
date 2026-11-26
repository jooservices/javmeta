<?php

declare(strict_types=1);

namespace App\Services\Api\V2\Me;

use App\Http\Api\V2\Envelope;
use App\Repositories\Api\V2\MeCatalogueRepository;
use App\Repositories\Api\V2\MeMarkRepository;
use App\Services\Api\V2\Catalogue\CatalogueService;
use DateTimeImmutable;

/** New movies of everything the user follows, and the time the user last looked. */
final class Feed
{
    public function __construct(
        private readonly MeMarkRepository $marks,
        private readonly MeCatalogueRepository $movies,
        private readonly CatalogueService $catalogue,
    ) {}

    /**
     * @param array<string, mixed> $query
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function page(int $userId, array $query): array
    {
        $page = Page::from($query);
        $seen = $this->seenUntil($userId);
        $follows = [];
        foreach ($this->marks->all($userId, 'follow') as $row) {
            $follows[(string) $row->subject][] = (string) $row->key;
        }

        $result = $follows === [] ? ['rows' => [], 'total' => 0] : $this->movies->feed($follows, $page->size, $page->offset());
        $limit = $seen === null ? null : new DateTimeImmutable($seen);
        $unseen = 0;
        $data = [];
        foreach ($this->catalogue->movieSummaries($result['rows']) as $movie) {
            $isNew = $limit === null || ($movie['crawled_at'] !== null && new DateTimeImmutable((string) $movie['crawled_at']) > $limit);
            $unseen += $isNew ? 1 : 0;
            $data[] = $movie + ['unseen' => $isNew];
        }

        return ['data' => $data, 'meta' => Envelope::pagination($page->number, $page->size, $result['total'], ['seen_until' => $seen, 'unseen' => $unseen])];
    }

    /** @return array{seen_until: string} */
    public function markSeen(int $userId, ?string $until): array
    {
        $at = $until === null ? new DateTimeImmutable() : new DateTimeImmutable($until);
        $this->marks->setFeedSeenUntil($userId, $at->format('Y-m-d H:i:sP'));

        return ['seen_until' => Timestamp::iso($at->format('Y-m-d H:i:sP'))];
    }

    private function seenUntil(int $userId): ?string
    {
        $stored = $this->marks->feedSeenUntil($userId);

        return $stored === null ? null : Timestamp::iso($stored);
    }
}
