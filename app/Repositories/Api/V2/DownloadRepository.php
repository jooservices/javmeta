<?php

declare(strict_types=1);

namespace App\Repositories\Api\V2;

use App\Models\MovieDownloadSearch;
use Illuminate\Support\Facades\DB;
use LogicException;

/** The rows behind the download requests of visitors and the download queue of the owner. */
final class DownloadRepository
{
    private const OPEN_STATES = ['queued', 'searching'];

    /** The state of the link search of a movie (the existing download-link search), or null when it never ran. */
    public function linkSearchOfMovie(int $movieId): ?MovieDownloadSearch
    {
        return MovieDownloadSearch::query()->where('movie_id', $movieId)->first();
    }

    /** @return list<object> */
    public function requestsOf(string $requester): array
    {
        return DB::table('download_requests')->where('requester', $requester)->where('state', '<>', 'cancelled')->orderByDesc('created_at')->orderByDesc('id')->limit(100)->get()->all();
    }

    public function requestOf(int $id, string $requester): ?object
    {
        return DB::table('download_requests')->where('id', $id)->where('requester', $requester)->first();
    }

    public function openRequest(string $requester, string $code): ?object
    {
        return DB::table('download_requests')->where('requester', $requester)->where('movie_code', $code)->whereIn('state', self::OPEN_STATES)->first();
    }

    public function createRequest(string $requester, string $code): object
    {
        $id = DB::table('download_requests')->insertGetId(['movie_code' => $code, 'requester' => $requester, 'state' => 'queued', 'created_at' => now()]);

        return DB::table('download_requests')->where('id', $id)->first() ?? throw new LogicException('Row vanished.');
    }

    /** @param array<string, mixed> $changes */
    public function updateRequest(int $id, array $changes): void
    {
        DB::table('download_requests')->where('id', $id)->update($changes);
    }

    public function cancelRequest(int $id, string $requester): void
    {
        DB::table('download_requests')->where('id', $id)->where('requester', $requester)->whereIn('state', self::OPEN_STATES)->update(['state' => 'cancelled']);
    }

    /** @return list<object> */
    public function items(int $page, int $perPage): array
    {
        return DB::table('download_items')->orderByDesc('added_at')->orderByDesc('id')->forPage($page, $perPage)->get()->all();
    }

    public function countItems(): int
    {
        return DB::table('download_items')->count();
    }

    public function item(string $hash): ?object
    {
        return DB::table('download_items')->where('hash', $hash)->first();
    }

    public function addItem(string $hash, string $magnet, ?string $movieCode): void
    {
        DB::table('download_items')->insert(['hash' => $hash, 'movie_code' => $movieCode, 'magnet' => $magnet, 'state' => 'queued', 'progress' => 0, 'added_at' => now(), 'updated_at' => now()]);
    }

    public function setItemState(string $hash, string $state): void
    {
        DB::table('download_items')->where('hash', $hash)->update(['state' => $state, 'updated_at' => now()]);
    }

    public function deleteItem(string $hash): void
    {
        DB::table('download_items')->where('hash', $hash)->delete();
    }

    /** @return array{total: int, running: int, paused: int} */
    public function itemStats(): array
    {
        $row = DB::table('download_items')->selectRaw("count(*) AS total, count(CASE WHEN state = 'running' THEN 1 END) AS running, count(CASE WHEN state = 'paused' THEN 1 END) AS paused")->first();

        return ['total' => (int) ($row->total ?? 0), 'running' => (int) ($row->running ?? 0), 'paused' => (int) ($row->paused ?? 0)];
    }

    /** @return list<object> finished files of a movie that the library scanner matched */
    public function filesOfMovie(string $code): array
    {
        return DB::table('library_files')->where('code', $code)->whereNull('missing_at')->orderBy('path')->get()->all();
    }
}
