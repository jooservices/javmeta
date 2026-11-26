<?php

declare(strict_types=1);

namespace App\Services\Api\V2\Catalogue;

use App\Http\Api\V2\Envelope;
use App\Http\Api\V2\ProblemException;
use App\Repositories\Api\V2\PerformerDetailRepository;
use App\Repositories\Api\V2\PerformerMovieFacetsRepository;
use Illuminate\Support\Facades\Cache;

/** One performer: profile, source records, photos, co-performers, statistics and movies; plus lookups and facet autocomplete. */
final class PerformerDetails
{
    private const MAX_CO_PERFORMERS = 30;

    private const STATISTICS_SECONDS = 120;

    public function __construct(
        private readonly PerformerDetailRepository $repository,
        private readonly PerformerMovieFacetsRepository $movieFacets,
        private readonly Formatter $formatter,
    ) {}

    /**
     * The profile of a performer: the summary plus bio and the merged profile fields.
     *
     * @return array{data: array<string, mixed>, slug: string}
     */
    public function profile(string $reference): array
    {
        $row = $this->repository->find($this->performerId($reference)) ?? throw $this->notFound($reference);
        $row->movie_count = $row->n_movies;
        $summary = $this->formatter->performer($row);

        return ['data' => $summary + ['bio_text' => $row->bio_text, 'profile' => $this->profileFields($this->decode($row->attrs))], 'slug' => $summary['slug']];
    }

    /** @return list<array<string, mixed>> */
    public function sources(string $reference): array
    {
        $id = $this->existing($reference);

        return array_map(fn(object $s): array => [
            'id' => (int) $s->id,
            'source' => $s->source,
            'external_id' => $s->external_id,
            'url' => $s->url,
            'name_romaji' => $s->name_romaji,
            'name_kanji' => $s->name_kanji,
            'birth_date' => $s->birth_date,
            'crawled_at' => $this->formatter->time($s->crawled_at),
            'active' => (bool) $s->active,
            'removed_at' => $this->formatter->time($s->removed_at),
        ], $this->repository->sources($id));
    }

    /** @return list<array{kind: string, url: string, source: ?string}> */
    public function photos(string $reference): array
    {
        $rows = $this->repository->photos($this->performerId($reference));

        return array_map(static fn(object $p): array => ['kind' => $p->kind, 'url' => $p->url, 'source' => $p->source], $rows);
    }

    /**
     * @param array<string, mixed> $query
     * @return list<array<string, mixed>>
     */
    public function coPerformers(string $reference, array $query): array
    {
        $limit = min(self::MAX_CO_PERFORMERS, max(1, (int) ($query['limit'] ?? 12) ?: 12));

        return array_map($this->formatter->performer(...), $this->repository->coPerformers($this->performerId($reference), $limit));
    }

    /** @return array<string, mixed> */
    public function statistics(string $reference): array
    {
        $id = $this->performerId($reference);

        return Cache::remember("api-v2:performer-statistics:{$id}", self::STATISTICS_SECONDS, fn(): array => $this->repository->statistics($id));
    }

    /**
     * @param array<string, mixed> $query
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function movies(string $reference, array $query): array
    {
        [$page, $perPage] = Paging::from($query, 12, 96);
        $result = $this->repository->movies($this->performerId($reference), (string) ($query['sort'] ?? '-release_date'), $perPage, ($page - 1) * $perPage);

        return ['data' => array_map(fn(object $row): array => $this->formatter->movie($row), $result['rows']), 'meta' => Envelope::pagination($page, $perPage, $result['total'])];
    }

    /**
     * @param list<int> $ids
     * @return list<array<string, mixed>>
     */
    public function lookup(array $ids): array
    {
        $found = [];

        foreach ($this->repository->byIds($ids) as $row) {
            $found[(int) $row->id] = $this->formatter->performer($row);
        }

        return array_values(array_filter(array_map(static fn(int $id): ?array => $found[$id] ?? null, array_unique($ids))));
    }

    /**
     * @param array<string, mixed> $query
     * @return list<array{value: string, count: int}>
     */
    public function facetValues(array $query): array
    {
        $facet = (string) ($query['facet'] ?? '');

        if (! in_array($facet, $this->repository->facets(), true)) {
            throw ProblemException::badRequest('invalid_facet', 'facet must be location, cup or blood_type.');
        }

        return $this->repository->facetValues($facet, (string) ($query['q'] ?? ''));
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function movieFacets(array $query): array
    {
        return $this->movieFacets->facets($query);
    }

    /** The numeric id at the start of "123" or "123-some-name". */
    private function performerId(string $reference): int
    {
        return preg_match('/^(\d+)/', $reference, $m) === 1 ? (int) $m[1] : throw $this->notFound($reference);
    }

    private function existing(string $reference): int
    {
        $id = $this->performerId($reference);

        return $this->repository->exists($id) ? $id : throw $this->notFound($reference);
    }

    private function notFound(string $reference): ProblemException
    {
        return ProblemException::notFound('performer_not_found', "No performer {$reference}.");
    }

    /** @return array<string, mixed> */
    private function decode(?string $json): array
    {
        return $json === null ? [] : (array) json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $attrs
     * @return array<string, mixed>
     */
    private function profileFields(array $attrs): array
    {
        $extra = (array) ($attrs['extra'] ?? []);
        $ratings = [];

        foreach ($extra as $key => $value) {
            if (str_starts_with((string) $key, 'rating_')) {
                $ratings[substr((string) $key, 7)] = $value;
            }
        }

        $favorites = $attrs['favorite_count_raw'] ?? null;

        return [
            'hobby' => $extra['hobby'] ?? null,
            'skill' => $extra['special_skill'] ?? null,
            'agency' => $extra['agency'] ?? null,
            'blog_url' => $extra['blog_url'] ?? null,
            'official_url' => $extra['official_url'] ?? null,
            'debut_work' => $extra['debut_work'] ?? null,
            'name_reading' => $extra['name_reading'] ?? null,
            'career_period' => $extra['career_period'] ?? null,
            'nickname' => $extra['nickname'] ?? null,
            'note' => $extra['other'] ?? null,
            'zodiac' => $attrs['zodiac_sign'] ?? null,
            'hair_color' => $attrs['hair_color'] ?? null,
            'hair_length' => $attrs['hair_length'] ?? null,
            'favorite_count' => $favorites === null ? null : ((int) $favorites ?: null),
            'tags' => array_values((array) ($attrs['tags'] ?? [])),
            'ratings' => (object) $ratings,
            'filmography_count' => isset($extra['movie_count']) ? (int) $extra['movie_count'] : null,
        ];
    }
}
