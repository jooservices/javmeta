<?php

declare(strict_types=1);

namespace App\Services\Api\V2\Downloads;

use App\Http\Api\V2\Envelope;
use App\Repositories\Api\V2\LibraryRepository;

/** What the library scanner knows about the files on disk. */
final class LibraryOverview
{
    public function __construct(private readonly LibraryRepository $repository) {}

    /** @return array<string, mixed> */
    public function summary(): array
    {
        $totals = $this->repository->totals();

        return $totals + [
            'media' => $this->repository->jobTotals(),
            'note' => $totals['files'] === 0 ? 'No library files have been scanned yet, so the library is empty.' : null,
        ];
    }

    /** @return array{data: list<array<string, mixed>>, meta: array<string, mixed>} */
    public function files(?string $match, int $page, int $perPage): array
    {
        $rows = array_map(static fn(object $row): array => [
            'id' => (int) $row->id,
            'path' => (string) $row->path,
            'folder' => (string) $row->folder,
            'size' => (int) $row->size,
            'code' => $row->code === null ? null : (string) $row->code,
            'first_seen_at' => Stamp::iso($row->first_seen_at),
            'last_seen_at' => Stamp::iso($row->last_seen_at),
            'missing_at' => Stamp::iso($row->missing_at),
        ], $this->repository->files($match, $page, $perPage));

        return ['data' => $rows, 'meta' => Envelope::pagination($page, $perPage, $this->repository->countFiles($match))];
    }

    /** @return list<string> */
    public function codes(): array
    {
        return $this->repository->codes();
    }

    /** @return list<array<string, mixed>> */
    public function mediaFiles(): array
    {
        return array_map(static fn(object $row): array => ['id' => (int) $row->id, 'path' => (string) $row->path, 'size' => (int) $row->size, 'code' => $row->code === null ? null : (string) $row->code], $this->repository->filesWithMedia());
    }
}
