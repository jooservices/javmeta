<?php

declare(strict_types=1);

namespace App\Services\Downloads;

use App\Models\MovieMedia;
use App\Services\Settings\SettingsService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The owner's downloads. Rules of the decision document (docs/decisions/downloads-and-capture-decision.md): off until the
 * owner switches it on; only a magnet JavMeta stored for a known movie is accepted; one explicit action per download; only
 * torrents of the JavMeta category are managed.
 */
final class DownloadService
{
    public function __construct(private readonly QBittorrentClient $client, private readonly SettingsService $settings) {}

    public function enabled(): bool
    {
        return (bool) $this->settings->get('downloads.enabled');
    }

    /** @return array{hash: string, movie_code: string} */
    public function add(string $movieCode): array
    {
        $this->assertEnabled();
        $movie = DB::table('movies')->whereRaw('UPPER(display_code) = ?', [strtoupper($movieCode)])->first(['id', 'display_code']);
        if ($movie === null) {
            throw new RuntimeException('Unknown movie.');
        }
        $magnet = DB::table('movie_media')->where('movie_id', $movie->id)->where('kind', MovieMedia::KIND_MAGNET)->orderByDesc('crawled_at')->value('url');
        if (! is_string($magnet) || ! str_starts_with($magnet, 'magnet:?')) {
            throw new RuntimeException('JavMeta has no magnet for this movie.');
        }

        $hash = $this->client->addMagnet($magnet, (string) $movie->display_code);
        DB::table('download_items')->insertOrIgnore(['hash' => $hash, 'movie_code' => $movie->display_code, 'magnet' => $magnet, 'added_at' => now()]);

        return ['hash' => $hash, 'movie_code' => (string) $movie->display_code];
    }

    /** @return list<array<string, mixed>> */
    public function list(): array
    {
        $this->assertEnabled();
        $codes = DB::table('download_items')->pluck('movie_code', 'hash')->all();
        $items = [];
        foreach ($this->client->list() as $t) {
            $hash = strtolower((string) ($t['hash'] ?? ''));
            $progress = (float) ($t['progress'] ?? 0);
            $items[] = [
                'hash' => $hash,
                'name' => (string) ($t['name'] ?? ''),
                'movie_code' => $codes[$hash] ?? (($t['tags'] ?? '') !== '' ? (string) $t['tags'] : null),
                'state' => $this->state((string) ($t['state'] ?? '')),
                'raw_state' => (string) ($t['state'] ?? ''),
                'progress' => round($progress * 100, 1),
                'size' => (int) ($t['size'] ?? 0),
                'speed' => (int) ($t['dlspeed'] ?? 0),
                'eta' => (int) ($t['eta'] ?? 0) >= 8640000 ? null : (int) ($t['eta'] ?? 0),
                'seeds' => (int) ($t['num_seeds'] ?? 0),
                'save_path' => (string) ($t['save_path'] ?? ''),
                'completed' => $progress >= 1.0,
            ];
        }

        return $items;
    }

    public function pause(string $hash): void
    {
        $this->assertOurs($hash);
        $this->client->pause($hash);
    }

    public function resume(string $hash): void
    {
        $this->assertOurs($hash);
        $this->client->resume($hash);
    }

    public function remove(string $hash, bool $withFiles): void
    {
        $this->assertOurs($hash);
        $this->client->delete($hash, $withFiles);
        DB::table('download_items')->where('hash', strtolower($hash))->delete();
    }

    /** The files of a completed download as absolute paths (the file server turns them into links). @return list<string> */
    public function filePaths(string $hash): array
    {
        $this->assertOurs($hash);
        $savePath = '';
        foreach ($this->client->list(['hashes' => $hash]) as $t) {
            $savePath = rtrim((string) ($t['save_path'] ?? ''), '/\\');
        }

        return array_values(array_map(static fn(array $f): string => $savePath . '/' . ltrim((string) ($f['name'] ?? ''), '/'), array_filter($this->client->files($hash), static fn($f): bool => is_array($f) && (float) ($f['progress'] ?? 0) >= 1.0)));
    }

    private function assertEnabled(): void
    {
        if (! $this->enabled()) {
            throw new RuntimeException('Downloads are switched off in Settings.');
        }
    }

    /** Only torrents of the JavMeta category may be managed. */
    private function assertOurs(string $hash): void
    {
        $this->assertEnabled();
        $hash = strtolower($hash);
        foreach ($this->client->list() as $t) {
            if (strtolower((string) ($t['hash'] ?? '')) === $hash) {
                return;
            }
        }

        throw new RuntimeException('Unknown download.');
    }

    private function state(string $raw): string
    {
        return match (true) {
            str_contains($raw, 'paused') || str_contains($raw, 'stopped') => 'paused',
            in_array($raw, ['downloading', 'forcedDL', 'metaDL', 'allocating'], true) => 'downloading',
            in_array($raw, ['uploading', 'stalledUP', 'forcedUP', 'queuedUP', 'checkingUP'], true) => 'completed',
            str_contains($raw, 'error') || $raw === 'missingFiles' => 'error',
            $raw === 'stalledDL' || $raw === 'queuedDL' => 'waiting',
            default => $raw === '' ? 'unknown' : $raw,
        };
    }
}
