<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\LibraryFile;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use JOOservices\LaravelRepository\Contracts\ReadRepositoryInterface;
use JOOservices\LaravelRepository\Repositories\EloquentRepository;
use JOOservices\LaravelRepository\Traits\HasFilter;
use JOOservices\LaravelRepository\Traits\HasOrder;
use JOOservices\LaravelRepository\Traits\HasRead;
use JOOservices\LaravelRepository\Traits\HasRequestQuery;

final class LibraryFileRepository extends EloquentRepository implements ReadRepositoryInterface
{
    use HasFilter;
    use HasOrder;
    use HasRead;
    use HasRequestQuery;

    public function __construct(LibraryFile $model)
    {
        parent::__construct($model);
    }

    /** @return array{files: int, matched: int, unmatched: int, missing: int, bytes: int, by_folder: list<array{folder: string, files: int, bytes: int}>, last_scan_at: ?string} */
    public function summary(): array
    {
        $present = $this->newQuery()->toBase()->whereNull('missing_at');
        $byFolder = (clone $present)->selectRaw('folder, COUNT(*) AS files, SUM(size) AS bytes')
            ->groupBy('folder')
            ->orderByDesc('bytes')
            ->get()
            ->map(static fn(object $row): array => ['folder' => (string) $row->folder, 'files' => (int) $row->files, 'bytes' => (int) $row->bytes])
            ->all();

        return [
            'files' => (int) (clone $present)->count(),
            'matched' => (int) (clone $present)->whereNotNull('movie_id')->count(),
            'unmatched' => (int) (clone $present)->whereNull('movie_id')->count(),
            'missing' => (int) $this->newQuery()->toBase()->whereNotNull('missing_at')->count(),
            'bytes' => (int) (clone $present)->sum('size'),
            'by_folder' => $byFolder,
            'last_scan_at' => $this->newQuery()->toBase()->max('last_seen_at'),
        ];
    }

    /** @return list<string> */
    public function codes(): array
    {
        /** @var list<string> $codes */
        $codes = $this->newQuery()->toBase()
            ->whereNull('missing_at')
            ->whereNotNull('code')
            ->distinct()
            ->pluck('code')
            ->all();

        return $codes;
    }

    /** @return array{files: list<object>, total: int} */
    public function unmatchedPage(int $page): array
    {
        $query = $this->newQuery()->toBase()->whereNull('missing_at')->whereNull('movie_id');
        /** @var list<object> $files */
        $files = (clone $query)->orderBy('path')->forPage($page, 100)->get(['path', 'size', 'folder'])->all();

        return ['files' => $files, 'total' => (int) $query->count()];
    }

    /** @return list<array{code: string, files: int, bytes: int}> */
    public function onDisk(): array
    {
        return $this->newQuery()->toBase()
            ->whereNull('missing_at')
            ->whereNotNull('code')
            ->select('code', DB::raw('COUNT(*) AS files'), DB::raw('SUM(size) AS bytes'))
            ->groupBy('code')
            ->orderBy('code')
            ->limit(500)
            ->get()
            ->map(static fn(object $row): array => ['code' => (string) $row->code, 'files' => (int) $row->files, 'bytes' => (int) $row->bytes])
            ->all();
    }

    /** @return list<int> */
    public function presentIdsInFolder(string $folder): array
    {
        return array_map('intval', $this->newQuery()->toBase()
            ->where('folder', $folder)
            ->whereNull('missing_at')
            ->pluck('id')
            ->all());
    }

    /** @param list<int> $ids */
    public function markMissing(array $ids, DateTimeInterface $at): int
    {
        if ($ids === []) {
            return 0;
        }

        return $this->newQuery()->whereIn('id', $ids)->update(['missing_at' => $at]);
    }

    /** @return object|null */
    public function findByPath(string $path): ?object
    {
        return $this->newQuery()->toBase()->where('path', $path)->first(['id', 'size', 'mtime', 'quick_hash', 'movie_id', 'code']);
    }

    /** @param array<string, mixed> $attributes */
    public function createFile(array $attributes): int
    {
        return (int) $this->newQuery()->create($attributes)->getKey();
    }

    /** @param array<string, mixed> $attributes */
    public function updateFile(int $id, array $attributes): void
    {
        $this->newQuery()->whereKey($id)->update($attributes);
    }
}
