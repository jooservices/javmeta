<?php

declare(strict_types=1);

namespace App\Services\Library;

use App\Services\Settings\SettingsService;
use App\Support\Code\NormalizedCode;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Reads file names and sizes under the configured folders, recognises movie codes in the names, matches them to the movies
 * JavMeta knows and records path, size and a quick hash (size plus the first and last bytes). Nothing leaves the machine and
 * no file is changed. Files that disappear are marked missing, never deleted from the table. Folders, extensions and the
 * hour of the daily scan are settings in the database.
 */
final class LibraryScanner
{
    public function __construct(private readonly SettingsService $settings) {}

    /** @return array{folders: int, seen: int, new: int, matched: int, unmatched: int, missing: int, errors: list<string>} */
    public function scan(): array
    {
        $folders = array_values(array_filter((array) $this->settings->get('library.folders'), static fn($f): bool => is_string($f) && $f !== ''));
        $extensions = array_map('strtolower', array_filter((array) $this->settings->get('library.extensions')));
        $hashBytes = max(1024, (int) $this->settings->get('library.hash_kilobytes') * 1024);
        $startedAt = now();
        $result = ['folders' => count($folders), 'seen' => 0, 'new' => 0, 'matched' => 0, 'unmatched' => 0, 'missing' => 0, 'errors' => []];

        $seen = [];
        foreach ($folders as $folder) {
            if (! is_dir($folder) || ! is_readable($folder)) {
                $result['errors'][] = "Folder not readable: {$folder}";
                continue;
            }
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder, RecursiveDirectoryIterator::SKIP_DOTS | RecursiveDirectoryIterator::FOLLOW_SYMLINKS), RecursiveIteratorIterator::LEAVES_ONLY, RecursiveIteratorIterator::CATCH_GET_CHILD);
            foreach ($iterator as $file) {
                /** @var SplFileInfo $file */
                if (! $file->isFile() || ! in_array(strtolower($file->getExtension()), $extensions, true)) {
                    continue;
                }
                $seen[$this->record($folder, $file, $hashBytes, $startedAt, $result)] = true;
            }
            // Under a folder that was read, whatever this run did not see is gone from the disk for now.
            $gone = array_values(array_filter(DB::table('library_files')->where('folder', $folder)->whereNull('missing_at')->pluck('id')->all(), static fn($id): bool => ! isset($seen[$id])));
            foreach (array_chunk($gone, 500) as $chunk) {
                $result['missing'] += DB::table('library_files')->whereIn('id', $chunk)->update(['missing_at' => $startedAt]);
            }
        }

        return $result;
    }

    /** The codes a file name may carry, best guess first (for example "SSIS-123", "ssis123", "[x] ABP_045"). @return list<string> */
    public function codesIn(string $fileName): array
    {
        $name = pathinfo($fileName, PATHINFO_FILENAME);
        $found = [];
        if (preg_match_all('/(?<![A-Za-z0-9])([A-Za-z]{2,6}[A-Za-z0-9]{0,3}?)[\s._-]?0*(\d{2,5})(?![0-9])/u', $name, $m, PREG_SET_ORDER) > 0) {
            foreach ($m as $hit) {
                try {
                    $found[NormalizedCode::from($hit[1] . '-' . $hit[2])->value()] = true;
                } catch (InvalidArgumentException) {
                    continue;
                }
            }
        }

        return array_keys($found);
    }

    /** @param array<string, mixed> $result */
    private function record(string $folder, SplFileInfo $file, int $hashBytes, \DateTimeInterface $startedAt, array &$result): int
    {
        $path = $file->getPathname();
        $size = (int) $file->getSize();
        $mtime = (int) $file->getMTime();
        $existing = DB::table('library_files')->where('path', $path)->first(['id', 'size', 'mtime', 'quick_hash', 'movie_id', 'code']);
        $result['seen']++;

        [$code, $movieId] = $existing !== null && $existing->size === $size && $existing->mtime === $mtime && $existing->movie_id !== null
            ? [$existing->code, $existing->movie_id]
            : $this->match($file->getFilename());
        $hash = $existing !== null && $existing->size === $size && $existing->mtime === $mtime && $existing->quick_hash !== null ? $existing->quick_hash : $this->quickHash($path, $size, $hashBytes);

        $row = ['folder' => $folder, 'size' => $size, 'mtime' => $mtime, 'quick_hash' => $hash, 'code' => $code, 'movie_id' => $movieId, 'last_seen_at' => $startedAt, 'missing_at' => null];
        if ($existing === null) {
            $id = (int) DB::table('library_files')->insertGetId([...$row, 'path' => $path, 'first_seen_at' => $startedAt]);
            $result['new']++;
        } else {
            $id = (int) $existing->id;
            DB::table('library_files')->where('id', $id)->update($row);
        }
        $result[$movieId !== null ? 'matched' : 'unmatched']++;

        return $id;
    }

    /** @return array{0: string|null, 1: int|null} display code and movie id */
    private function match(string $fileName): array
    {
        foreach ($this->codesIn($fileName) as $normalized) {
            $movie = DB::table('movie_codes')->join('movies', 'movies.id', '=', 'movie_codes.movie_id')->where('movie_codes.code_normalized', $normalized)->first(['movies.id', 'movies.display_code']);
            if ($movie !== null) {
                return [(string) $movie->display_code, (int) $movie->id];
            }
        }

        return [null, null];
    }

    private function quickHash(string $path, int $size, int $bytes): ?string
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }
        try {
            $head = (string) fread($handle, $bytes);
            $tail = '';
            if ($size > $bytes * 2) {
                fseek($handle, -$bytes, SEEK_END);
                $tail = (string) fread($handle, $bytes);
            }

            return sha1($size . '|' . $head . '|' . $tail);
        } finally {
            fclose($handle);
        }
    }
}
