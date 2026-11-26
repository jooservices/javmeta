<?php

declare(strict_types=1);

namespace App\Repositories\Api\V2;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** The rows behind the library of the owner: scanned files, media jobs and the HLS addresses of a movie. */
final class LibraryRepository
{
    /** @return array{files: int, matched: int, unmatched: int, missing: int, bytes: int} */
    public function totals(): array
    {
        $row = DB::table('library_files')->selectRaw('count(*) AS files, count(code) AS matched, count(CASE WHEN code IS NULL THEN 1 END) AS unmatched, count(missing_at) AS missing, coalesce(sum(size), 0) AS bytes')->first();

        return ['files' => (int) ($row->files ?? 0), 'matched' => (int) ($row->matched ?? 0), 'unmatched' => (int) ($row->unmatched ?? 0), 'missing' => (int) ($row->missing ?? 0), 'bytes' => (int) ($row->bytes ?? 0)];
    }

    /** @return array{jobs: int, failed: int} */
    public function jobTotals(): array
    {
        return ['jobs' => DB::table('media_jobs')->count(), 'failed' => DB::table('media_jobs')->where('status', 'failed')->count()];
    }

    /** @return list<object> */
    public function files(?string $match, int $page, int $perPage): array
    {
        return $this->filtered($match)->orderByDesc('last_seen_at')->orderByDesc('id')->forPage($page, $perPage)->get(['id', 'path', 'folder', 'size', 'code', 'first_seen_at', 'last_seen_at', 'missing_at'])->all();
    }

    public function countFiles(?string $match): int
    {
        return $this->filtered($match)->count();
    }

    /** @return list<string> */
    public function codes(): array
    {
        return DB::table('library_files')->whereNotNull('code')->distinct()->orderBy('code')->limit(5000)->pluck('code')->map(static fn(mixed $code): string => (string) $code)->all();
    }

    /** @return list<object> files that have a media job, a scene or a subtitle */
    public function filesWithMedia(): array
    {
        return DB::table('library_files AS f')
            ->whereExists(static fn(Builder $q) => $q->selectRaw('1')->from('media_jobs AS j')->whereColumn('j.library_file_id', 'f.id'))
            ->orWhereExists(static fn(Builder $q) => $q->selectRaw('1')->from('media_scenes AS s')->whereColumn('s.library_file_id', 'f.id'))
            ->orWhereExists(static fn(Builder $q) => $q->selectRaw('1')->from('media_subtitles AS t')->whereColumn('t.library_file_id', 'f.id'))
            ->orderByDesc('f.last_seen_at')->orderByDesc('f.id')->limit(100)->get(['f.id', 'f.path', 'f.size', 'f.code'])->all();
    }

    /** @return list<object> */
    public function jobs(int $page, int $perPage): array
    {
        return DB::table('media_jobs')->orderByDesc('created_at')->orderByDesc('id')->forPage($page, $perPage)->get(['id', 'kind', 'source_url', 'status', 'attempts', 'error', 'created_at', 'started_at', 'finished_at'])->all();
    }

    public function countJobs(): int
    {
        return DB::table('media_jobs')->count();
    }

    public function job(int $id): ?object
    {
        return DB::table('media_jobs')->where('id', $id)->first(['id', 'kind', 'source_url', 'status', 'attempts', 'error', 'created_at', 'started_at', 'finished_at']);
    }

    public function fileIsPresent(int $id): bool
    {
        return DB::table('library_files')->where('id', $id)->whereNull('missing_at')->exists();
    }

    /** A failed job goes back to the queue; false when there was nothing to retry. */
    public function retryFailedJob(int $id): bool
    {
        return DB::table('media_jobs')->where('id', $id)->where('status', 'failed')->update(['status' => 'pending', 'attempts' => 0, 'error' => null, 'finished_at' => null, 'claim_token' => null]) === 1;
    }

    /** @return list<object> */
    public function hlsOf(int $movieId): array
    {
        return DB::table('movie_media')->where('movie_id', $movieId)->where('kind', 'hls')->orderBy('id')->limit(20)->get(['url', 'meta'])->all();
    }

    private function filtered(?string $match): Builder
    {
        $query = DB::table('library_files');

        return match ($match) {
            'matched' => $query->whereNotNull('code'),
            'unmatched' => $query->whereNull('code'),
            default => $query,
        };
    }
}
