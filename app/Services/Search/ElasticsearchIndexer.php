<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Models\Performer;
use App\Models\Movie;
use App\Observability\ObservabilityEmitter;
use App\Services\Embedding\EmbedderClient;
use DateTimeInterface;
use Throwable;

/**
 * Elasticsearch index for search; documents carry uuid linking back to Postgres.
 */
final class ElasticsearchIndexer
{
    public function __construct(
        private readonly EmbedderClient $embedder,
        private readonly ElasticsearchHttpClient $elasticsearch,
    ) {}

    public function indexMovie(Movie $movie, bool $requireVector = false): bool
    {
        if (! (bool) config('elasticsearch.enabled', true)) {
            return false;
        }

        $movie->loadMissing(['genres', 'performers']);

        $document = [
            'movie_id' => $movie->id,
            'uuid' => $movie->uuid,
            'code' => $movie->display_code,
            'code_normalized' => $movie->code_normalized,
            'title_jp' => $movie->title_jp,
            'title_en' => $movie->title_en,
            'description' => $movie->description,
            'maker' => $movie->maker,
            'label' => $movie->label,
            'series' => $movie->series,
            'genres' => $movie->genres->pluck('label_normalized')->filter()->values()->all(),
            'performer_names' => $movie->performers
                ->map(static fn(Performer $p): string => (string) ($p->name_romaji ?? $p->name_kanji ?? ''))
                ->filter()
                ->values()
                ->all(),
            'release_date' => $this->dateString($movie->getAttribute('release_date')),
            'runtime_minutes' => $movie->runtime_minutes,
            'censored' => $movie->censored,
            'community_score' => $movie->community_score !== null ? (float) $movie->community_score : null,
            'updated_at' => $this->dateTimeString($movie->getAttribute('updated_at')),
            'crawled_at' => $this->dateTimeString($movie->getAttribute('crawled_at')),
        ];

        $embedText = implode(' ', array_filter([
            (string) $movie->title_en,
            (string) $movie->title_jp,
            implode(', ', $document['genres']),
            (string) $movie->maker,
            (string) $movie->series,
            (string) $movie->description,
        ]));
        $vector = $this->embedder->embedOne($embedText);
        if ($vector !== null) {
            $document['embedding_movie'] = $vector;
        } elseif ($requireVector) {
            return false;
        }

        return $this->put((string) config('elasticsearch.movies_index'), (string) $movie->uuid, $document);
    }

    public function indexPerformer(Performer $performer, bool $requireVector = false): bool
    {
        if (! (bool) config('elasticsearch.enabled', true)) {
            return false;
        }

        $performer->loadMissing('aliases');

        $aliases = $performer->aliases->pluck('alias')->filter()->values()->all();
        $document = [
            'performer_id' => $performer->id,
            'uuid' => $performer->uuid,
            'name_romaji' => $performer->name_romaji,
            'name_kanji' => $performer->name_kanji,
            'name_kana' => $performer->name_kana,
            'aliases' => $aliases,
            'birth_date' => $this->dateString($performer->getAttribute('birth_date')),
            'bio_text' => $performer->bio_text,
            'cup' => $performer->cup,
            'debut_date' => $this->dateString($performer->getAttribute('debut_date')),
            'location' => $performer->location,
            'updated_at' => $this->dateTimeString($performer->getAttribute('updated_at')),
        ];

        $embedText = implode(' ', array_filter([
            (string) $performer->name_romaji,
            (string) $performer->name_kanji,
            (string) $performer->name_kana,
            implode(', ', $aliases),
            (string) $performer->bio_text,
            $this->dateString($performer->getAttribute('birth_date')),
            $this->dateString($performer->getAttribute('debut_date')),
            (string) $performer->location,
            (string) $performer->cup,
            (string) $performer->blood_type,
        ]));
        $vector = $this->embedder->embedOne($embedText);
        if ($vector !== null) {
            $document['embedding_performer'] = $vector;
        } elseif ($requireVector) {
            return false;
        }

        return $this->put((string) config('elasticsearch.performers_index'), (string) $performer->uuid, $document);
    }

    /**
     * @return list<string> uuids
     */
    public function searchMovieUuids(string $query, int $size = 20): array
    {
        $index = (string) config('elasticsearch.movies_index');

        try {
            $response = $this->elasticsearch->post('search', "/{$index}/_search", [
                'size' => $size,
                'query' => [
                    'multi_match' => [
                        'query' => $query,
                        'fields' => [
                            'code^5',
                            'code_normalized^5',
                            'title_jp^3',
                            'title_en^3',
                            'performer_names^2',
                            'maker',
                            'label',
                            'series',
                            'genres',
                            'description',
                        ],
                    ],
                ],
            ]);

            if (! $response->successful()) {
                return [];
            }

            $hits = $response->json('hits.hits') ?? [];
            $uuids = [];
            foreach ($hits as $hit) {
                $uuid = $hit['_source']['uuid'] ?? $hit['_id'] ?? null;
                if (is_string($uuid) && $uuid !== '') {
                    $uuids[] = $uuid;
                }
            }

            return $uuids;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Semantic (kNN) search over movie title/description embeddings.
     *
     * @return list<string> uuids
     */
    public function semanticMovieUuids(string $query, int $size = 20): array
    {
        return $this->semanticUuids(
            (string) config('elasticsearch.movies_index'),
            'embedding_movie',
            $query,
            $size,
        );
    }

    /**
     * @return list<string> uuids
     */
    public function semanticPerformerUuids(string $query, int $size = 20): array
    {
        return $this->semanticUuids(
            (string) config('elasticsearch.performers_index'),
            'embedding_performer',
            $query,
            $size,
        );
    }

    /**
     * @return list<string> uuids
     */
    private function semanticUuids(string $index, string $embeddingField, string $query, int $size): array
    {
        $vector = $this->embedder->embedOne($query, true);
        if ($vector === null) {
            return [];
        }

        try {
            $response = $this->elasticsearch->post('search', "/{$index}/_search", [
                'size' => $size,
                'knn' => [
                    'field' => $embeddingField,
                    'query_vector' => $vector,
                    'k' => $size,
                    'num_candidates' => max($size * 10, 100),
                ],
            ]);

            if (! $response->successful()) {
                return [];
            }

            $uuids = [];
            foreach ($response->json('hits.hits') ?? [] as $hit) {
                $uuid = $hit['_source']['uuid'] ?? $hit['_id'] ?? null;
                if (is_string($uuid) && $uuid !== '') {
                    $uuids[] = $uuid;
                }
            }

            return $uuids;
        } catch (Throwable) {
            return [];
        }
    }

    /** @param array<string, mixed> $body */
    private function put(string $index, string $id, array $body): bool
    {
        $started = hrtime(true);

        try {
            $response = $this->elasticsearch->put('index', "/{$index}/_doc/{$id}", $body);
            app(ObservabilityEmitter::class)->emitDependency(
                'elasticsearch',
                'index',
                $response->successful(),
                (int) round((hrtime(true) - $started) / 1_000_000),
            );

            return $response->successful();
        } catch (Throwable) {
            app(ObservabilityEmitter::class)->emitDependency(
                'elasticsearch',
                'index',
                false,
                (int) round((hrtime(true) - $started) / 1_000_000),
            );

            return false;
        }
    }

    private function dateString(mixed $value): ?string
    {
        return $value instanceof DateTimeInterface ? $value->format('Y-m-d') : null;
    }

    private function dateTimeString(mixed $value): ?string
    {
        return $value instanceof DateTimeInterface ? $value->format(DATE_ATOM) : null;
    }
}
