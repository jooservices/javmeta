<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Movie;
use App\Services\Movies\MovieLookupService;
use Illuminate\Console\Command;

final class VerifySampleCommand extends Command
{
    protected $signature = 'javmeta:verify-sample {file : a text file with one movie code per line} {--json}';

    protected $description = 'Compare a list of movie codes with the stored data: missing codes and empty core fields.';

    public function handle(MovieLookupService $lookup): int
    {
        $path = (string) $this->argument('file');
        if (! is_file($path) || ! is_readable($path)) {
            $this->components->error('The sample file cannot be read.');

            return self::FAILURE;
        }

        $codes = array_values(array_unique(array_filter(array_map('trim', file($path, FILE_IGNORE_NEW_LINES) ?: []), static fn(string $line): bool => $line !== '' && ! str_starts_with($line, '#'))));
        $missing = [];
        $incomplete = [];
        $found = 0;

        foreach ($codes as $code) {
            $movie = $lookup->findByCode($code);
            if (! $movie instanceof Movie) {
                $missing[] = $code;

                continue;
            }
            $found++;
            $empty = $this->emptyFields($movie);
            if ($empty !== []) {
                $incomplete[$code] = $empty;
            }
        }

        $result = [
            'codes' => count($codes),
            'found' => $found,
            'found_percent' => count($codes) === 0 ? 0.0 : round($found / count($codes) * 100, 1),
            'missing' => $missing,
            'with_empty_core_fields' => $incomplete,
        ];
        $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function emptyFields(Movie $movie): array
    {
        $empty = [];
        foreach (['title_jp', 'title_en', 'cover_url', 'release_date', 'maker', 'label', 'series'] as $field) {
            $value = $movie->getAttribute($field);
            if ($value === null || trim((string) $value) === '') {
                $empty[] = $field;
            }
        }
        if ($movie->runtime_minutes === null) {
            $empty[] = 'runtime';
        }
        if (! $movie->performers()->exists()) {
            $empty[] = 'actresses';
        }
        if (! $movie->genres()->exists()) {
            $empty[] = 'genres';
        }

        return $empty;
    }
}
