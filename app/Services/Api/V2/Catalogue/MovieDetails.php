<?php

declare(strict_types=1);

namespace App\Services\Api\V2\Catalogue;

use App\Http\Api\V2\ProblemException;
use App\Repositories\Api\V2\CatalogueRepository;
use App\Repositories\Api\V2\MovieDetailRepository;
use App\Services\Api\V2\Contracts\MovieViewCounter;

/** One movie: detail, codes, sources, media, downloads, related movies, view counts, lookups and facet autocomplete. */
final class MovieDetails
{
    private const MAX_RELATED = 24;

    private const MAX_FACET_VALUES = 50;

    private const MAX_VIEW_CODES = 200;

    public function __construct(
        private readonly MovieDetailRepository $repository,
        private readonly CatalogueRepository $catalogue,
        private readonly Formatter $formatter,
        private readonly MovieViewCounter $views,
    ) {}

    /** @return array<string, mixed> */
    public function detail(string $code): array
    {
        $row = $this->movie($code);
        $summary = $this->formatter->movie($row);
        $extra = $this->repository->extras((int) $row->id);

        return $summary + [
            'description' => $extra?->description,
            'uuid' => $extra?->uuid,
            'attrs' => $this->decode($extra?->attrs) ?? [],
            'performers' => $this->cast($summary['performer_ids']),
            'delisted_at' => $this->formatter->time($extra?->delisted_at),
        ];
    }

    /**
     * @return array{data: list<array<string, mixed>>, markers: list<string>}
     */
    public function codes(string $code): array
    {
        $row = $this->movie($code);
        $codes = $this->repository->codes((int) $row->id);
        $markers = [];

        if ($row->needs_review) {
            $markers[] = 'needs_review';
        }

        if ($row->delisted) {
            $markers[] = 'delisted';
        }

        if (array_filter($codes, static fn(object $c): bool => $c->kind === 'derived') !== []) {
            $markers[] = 'derived_code';
        }

        return [
            'data' => array_map(fn(object $c): array => ['code' => $c->code, 'kind' => $c->kind, 'source_slug' => $c->source_slug, 'source_url' => $c->source_url, 'crawled_at' => $this->formatter->time($c->crawled_at)], $codes),
            'markers' => $markers,
        ];
    }

    /** @return list<array<string, mixed>> */
    public function sources(string $code): array
    {
        $rows = $this->repository->sources((int) $this->movie($code)->id);

        return array_map(fn(object $s): array => ['source' => $s->source, 'url' => $s->url, 'code' => $s->code, 'crawled_at' => $this->formatter->time($s->crawled_at)], $rows);
    }

    /** @return array{gallery: list<array<string, mixed>>, hls: list<array<string, mixed>>, samples: list<array<string, mixed>>} */
    public function media(string $code): array
    {
        $rows = $this->repository->media((int) $this->movie($code)->id);
        $media = ['gallery' => [], 'hls' => [], 'samples' => []];

        foreach ($rows as $row) {
            $bucket = match ($row->kind) {
                'hls' => 'hls',
                'sample' => 'samples',
                default => 'gallery',
            };
            $media[$bucket][] = ['kind' => $row->kind, 'url' => $row->url, 'meta' => $this->decode($row->meta), 'source' => $row->source];
        }

        return $media;
    }

    /** @return list<array<string, mixed>> */
    public function downloads(string $code): array
    {
        $links = [];

        foreach ($this->repository->magnets((int) $this->movie($code)->id) as $row) {
            $meta = $this->decode($row->meta) ?? [];
            $links[] = [
                'type' => str_starts_with((string) $row->url, 'magnet:') ? 'magnet' : 'torrent',
                'url' => $row->url,
                'name' => $meta['name'] ?? null,
                'size' => isset($meta['size']) ? (string) $meta['size'] : null,
                'bytes' => isset($meta['bytes']) ? (int) $meta['bytes'] : null,
                'quality' => $meta['quality'] ?? null,
                'source' => $row->source,
            ];
        }

        return $links;
    }

    /**
     * @param array<string, mixed> $query
     * @return list<array<string, mixed>>
     */
    public function related(string $code, array $query): array
    {
        $limit = min(self::MAX_RELATED, max(1, (int) ($query['limit'] ?? 12) ?: 12));

        return array_map($this->formatter->movie(...), $this->repository->related((int) $this->movie($code)->id, $limit));
    }

    /**
     * @param list<string> $codes
     * @return array{data: list<array<string, mixed>>, missing: list<string>}
     */
    public function lookup(array $codes): array
    {
        $found = [];

        foreach ($this->repository->byCodes($codes) as $row) {
            $found[$row->code] = $this->formatter->movie($row);
        }

        $data = [];
        $missing = [];

        foreach ($codes as $code) {
            $key = mb_strtoupper($code);

            if (isset($found[$key])) {
                $data[$key] = $found[$key];
            } else {
                $missing[] = $code;
            }
        }

        return ['data' => array_values($data), 'missing' => $missing];
    }

    /**
     * @param array<string, mixed> $query
     * @return list<array{value: string, count: int}>
     */
    public function facetValues(array $query): array
    {
        $facet = (string) ($query['facet'] ?? '');

        if (! in_array($facet, $this->repository->facets(), true)) {
            throw ProblemException::badRequest('invalid_facet', 'facet must be genre, maker, series or label.');
        }

        $limit = min(self::MAX_FACET_VALUES, max(1, (int) ($query['limit'] ?? 20) ?: 20));

        return $this->repository->facetValues($facet, (string) ($query['q'] ?? ''), $limit);
    }

    /** @return array<string, int> view count per requested code (comma-separated list) */
    public function viewCounts(string $codes): array
    {
        $list = array_slice(array_values(array_filter(array_map('trim', explode(',', $codes)), static fn(string $c): bool => $c !== '')), 0, self::MAX_VIEW_CODES);

        return $this->views->counts($list);
    }

    /** Counts one view and returns the movie's total. */
    public function recordView(string $code, ?int $userId): int
    {
        return $this->views->record((string) $this->movie($code)->code, $userId);
    }

    private function movie(string $code): object
    {
        return $this->repository->findByCode($code) ?? throw ProblemException::notFound('movie_not_found', "No movie with code {$code}.");
    }

    /**
     * @param list<int> $ids
     * @return list<array<string, mixed>>
     */
    private function cast(array $ids): array
    {
        $performers = $ids === [] ? [] : $this->catalogue->performersByIds($ids);
        usort($performers, static fn(object $a, object $b): int => [$b->movie_count, $a->id] <=> [$a->movie_count, $b->id]);

        return array_map($this->formatter->performer(...), $performers);
    }

    /** @return array<string, mixed>|null */
    private function decode(?string $json): ?array
    {
        return $json === null ? null : json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }
}
