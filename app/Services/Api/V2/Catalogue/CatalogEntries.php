<?php

declare(strict_types=1);

namespace App\Services\Api\V2\Catalogue;

use App\Http\Api\V2\Envelope;
use App\Http\Api\V2\ProblemException;
use App\Repositories\Api\V2\CatalogEntriesRepository;
use Illuminate\Support\Facades\Cache;
use Normalizer;

/** The browsable catalogues (genres, makers, series, labels, years, cups, blood types, locations): entries, script index, one entry. */
final class CatalogEntries
{
    private const CACHE_SECONDS = 600;

    private const MAX_PER_PAGE = 200;

    /** The list filter each kind maps to. */
    private const FILTERS = ['genres' => 'genre', 'makers' => 'maker', 'series' => 'series', 'labels' => 'label', 'years' => 'released_from', 'cups' => 'cup', 'blood-types' => 'blood_type', 'locations' => 'location'];

    private const SCRIPT_LABELS = ['latin' => 'Latin', 'ja' => '日本語', 'zh' => '中文', 'other' => 'Khác'];

    public function __construct(private readonly CatalogEntriesRepository $repository) {}

    /** @return list<array{kind: string, target: string, filter: string}> */
    public function kinds(): array
    {
        return array_map(fn(string $kind): array => ['kind' => $kind, 'target' => $this->target($kind), 'filter' => self::FILTERS[$kind]], $this->repository->kinds());
    }

    /**
     * @param array<string, mixed> $query
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function page(string $kind, array $query): array
    {
        $entries = $this->entries($kind);
        $script = (string) ($query['script'] ?? 'all');
        $minimum = (int) ($query['min_count'] ?? 0);
        $term = mb_strtolower(trim((string) ($query['q'] ?? '')));
        $letter = mb_strtoupper((string) ($query['letter'] ?? ''));

        $entries = array_values(array_filter($entries, static fn(array $e): bool => ($script === 'all' || $e['script'] === $script)
            && $e['count'] >= $minimum
            && ($term === '' || str_contains(mb_strtolower($e['name']), $term))
            && ($letter === '' || str_starts_with(mb_strtoupper(trim($e['name'])), $letter))));

        $sort = (string) ($query['sort'] ?? '-count');
        $factor = str_starts_with($sort, '-') ? -1 : 1;
        $byName = ltrim($sort, '-') === 'name';
        usort($entries, static fn(array $a, array $b): int => $factor * ($byName ? strcmp($a['name'], $b['name']) : ($a['count'] <=> $b['count'] ?: strcmp($a['name'], $b['name']))));

        [$page, $perPage] = Paging::from($query, 60, self::MAX_PER_PAGE);

        return ['data' => array_slice($entries, ($page - 1) * $perPage, $perPage), 'meta' => Envelope::pagination($page, $perPage, count($entries), ['total_entries' => count($entries)])];
    }

    /**
     * Scripts present in a catalogue with the first letters used under each.
     *
     * @param array<string, mixed> $query
     * @return list<array{script: string, label: string, count: int, letters: list<array{letter: string, count: int}>}>
     */
    public function index(string $kind, array $query): array
    {
        $minimum = (int) ($query['min_count'] ?? 0);
        $scripts = [];

        foreach ($this->entries($kind) as $entry) {
            if ($entry['count'] < $minimum) {
                continue;
            }

            $letter = mb_strtoupper(mb_substr(trim($entry['name']), 0, 1));
            $scripts[$entry['script']]['letters'][$letter] = ($scripts[$entry['script']]['letters'][$letter] ?? 0) + 1;
            $scripts[$entry['script']]['count'] = ($scripts[$entry['script']]['count'] ?? 0) + 1;
        }

        $result = [];

        foreach (array_keys(self::SCRIPT_LABELS) as $script) {
            if (! isset($scripts[$script])) {
                continue;
            }

            $data = $scripts[$script];
            ksort($data['letters'], SORT_STRING);
            $result[] = [
                'script' => $script,
                'label' => self::SCRIPT_LABELS[$script],
                'count' => $data['count'],
                'letters' => array_map(static fn(string|int $letter, int $count): array => ['letter' => (string) $letter, 'count' => $count], array_keys($data['letters']), $data['letters']),
            ];
        }

        return $result;
    }

    /** @return array<string, mixed> */
    public function entry(string $kind, string $slug): array
    {
        foreach ($this->entries($kind) as $entry) {
            if ($entry['slug'] === $slug || $entry['name'] === $slug) {
                return $entry;
            }
        }

        throw ProblemException::notFound('catalog_entry_not_found', "No entry {$slug} in {$kind}.");
    }

    /** @return list<array<string, mixed>> */
    private function entries(string $kind): array
    {
        if (! in_array($kind, $this->repository->kinds(), true)) {
            throw ProblemException::notFound('catalog_not_found', "Unknown catalogue {$kind}.");
        }

        return Cache::remember("api-v2:catalog:{$kind}", self::CACHE_SECONDS, fn(): array => array_map(fn(array $row): array => [
            'slug' => $this->slug($row['name']),
            'name' => $row['name'],
            'count' => $row['count'],
            'cover_url' => $row['cover'],
            'script' => $this->script($row['name']),
            'kind' => $this->target($kind),
            'filter' => self::FILTERS[$kind],
        ], $this->repository->entries($kind)));
    }

    private function target(string $kind): string
    {
        return $this->repository->isPerformerKind($kind) ? 'performers' : 'movies';
    }

    private function slug(string $name): string
    {
        $folded = (string) Normalizer::normalize(mb_strtolower($name), Normalizer::FORM_KD);
        $slug = trim((string) preg_replace('/[^\p{L}\p{N}\p{M}]+/u', '-', (string) preg_replace('/[\x{0300}-\x{036F}]/u', '', $folded)), '-');

        return $slug === '' ? $name : (string) Normalizer::normalize($slug, Normalizer::FORM_C);
    }

    /** The writing system of the first character. */
    private function script(string $name): string
    {
        $first = mb_substr(trim($name), 0, 1);
        $code = $first === '' ? 0 : mb_ord($first);

        return match (true) {
            ($code >= 65 && $code <= 90) || ($code >= 97 && $code <= 122) || ($code >= 48 && $code <= 57) => 'latin',
            ($code >= 0x3040 && $code <= 0x30FF) || ($code >= 0x31F0 && $code <= 0x31FF) || ($code >= 0xFF66 && $code <= 0xFF9F) => 'ja',
            $code >= 0x4E00 && $code <= 0x9FFF => 'zh',
            default => 'other',
        };
    }
}
