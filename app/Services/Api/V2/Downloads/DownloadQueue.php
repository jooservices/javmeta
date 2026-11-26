<?php

declare(strict_types=1);

namespace App\Services\Api\V2\Downloads;

use App\Http\Api\V2\Envelope;
use App\Http\Api\V2\ProblemException;
use App\Repositories\Api\V2\DownloadRepository;
use App\Services\Downloads\DownloadService;

/** The owner's download queue: magnets tracked by hash, with a state the owner can pause and resume. */
final class DownloadQueue
{
    public function __construct(
        private readonly DownloadRepository $repository,
        private readonly DownloadService $downloads,
        private readonly FileLinks $links,
    ) {}

    /** @return array{data: list<array<string, mixed>>, meta: array<string, mixed>} */
    public function page(int $page, int $perPage): array
    {
        $rows = array_map($this->view(...), $this->repository->items($page, $perPage));

        return ['data' => $rows, 'meta' => Envelope::pagination($page, $perPage, $this->repository->countItems())];
    }

    /** @return array<string, mixed> */
    public function one(string $hash): array
    {
        return $this->view($this->find($hash));
    }

    /** @return array<string, mixed> */
    public function add(string $magnet, ?string $movieCode): array
    {
        preg_match('/btih:([A-Za-z0-9]+)/i', $magnet, $found);
        $hash = strtolower($found[1]);

        if ($this->repository->item($hash) !== null) {
            throw ProblemException::conflict('already_added', 'This download is already in the queue.');
        }

        $this->repository->addItem($hash, $magnet, $movieCode === null || trim($movieCode) === '' ? null : strtoupper(trim($movieCode)));

        return $this->view($this->find($hash));
    }

    /** @return array<string, mixed> */
    public function setState(string $hash, string $state): array
    {
        $this->find($hash);
        $this->repository->setItemState(strtolower($hash), $state);

        return $this->view($this->find($hash));
    }

    public function remove(string $hash): void
    {
        $this->repository->deleteItem(strtolower($hash));
    }

    /** @return array<string, mixed> */
    public function status(): array
    {
        $connected = $this->downloads->enabled();

        return $this->repository->itemStats() + [
            'client' => $connected ? 'qbittorrent' : 'not_connected',
            'note' => $connected ? 'Items are handed to the torrent client.' : 'No torrent client is connected; items are tracked but not transferred.',
        ];
    }

    /** @return list<array<string, mixed>> signed links to the finished files of the item's movie */
    public function links(string $hash): array
    {
        $item = $this->find($hash);

        if ($item->movie_code === null) {
            return [];
        }

        $links = [];

        foreach ($this->repository->filesOfMovie((string) $item->movie_code) as $file) {
            $url = $this->links->linkFor((string) $file->path);

            if ($url !== null) {
                $links[] = ['name' => basename((string) $file->path), 'size' => (int) $file->size, 'url' => $url];
            }
        }

        return $links;
    }

    private function find(string $hash): object
    {
        return $this->repository->item(strtolower($hash)) ?? throw ProblemException::notFound('download_not_found', 'No such download.');
    }

    /** @return array<string, mixed> */
    private function view(object $row): array
    {
        return [
            'hash' => (string) $row->hash,
            'movie_code' => $row->movie_code === null ? null : (string) $row->movie_code,
            'state' => (string) $row->state,
            'progress' => (int) $row->progress,
            'size_bytes' => $row->size_bytes === null ? null : (int) $row->size_bytes,
            'added_at' => Stamp::iso($row->added_at),
            'updated_at' => Stamp::iso($row->updated_at),
        ];
    }
}
