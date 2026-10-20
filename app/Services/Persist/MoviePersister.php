<?php

declare(strict_types=1);

namespace App\Services\Persist;

use App\Data\Crawl\MovieDraft;
use App\Models\CrawlRun;
use App\Models\Genre;
use App\Models\Performer;
use App\Models\PerformerAlias;
use App\Models\ReviewFlag;
use App\Models\Source;
use App\Models\Movie;
use App\Models\MovieCode;
use App\Models\MovieCredit;
use App\Models\MovieGenre;
use App\Models\MovieMedia;
use App\Models\MovieObservation;
use App\Models\MoviePerformer;
use App\Services\Crawl\SourceActivityRecorder;
use App\Services\Crawl\SourceThrottle;
use App\Services\Crawl\MovieDraftSink;
use App\Services\Merge\ConflictPolicy;
use App\Services\Merge\MovieMerger;
use App\Support\Code\NormalizedCode;
use App\Observability\ObservabilityEmitter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;

/**
 * Persists a normalized MovieDraft in one transaction (ARCHITECTURE 2.2 step 5).
 *
 * - movie_observations is append-only: every crawl appends one row per field
 *   (including explicit nulls, i.e. "this source reported nothing"), and no
 *   code path updates or deletes an observation (AD-7, AC-1.2).
 * - The primary value per field is chosen by the injected ConflictPolicy and
 *   projected onto movies under two drift guards (R-4):
 *   NULL-OVERWRITE: a null projection never replaces an existing non-null
 *   value; SHARP-DROP: a projection losing >= 3 non-null core fields keeps
 *   every current value, sets needs_review and writes a review_flags row.
 * - Genres are a union by normalized label with per-source provenance
 *   (AC-1.6/D-24), no canonical taxonomy.
 * - Media is URL-reference only (BR-4/AD-16); no bytes are stored.
 */
final class MoviePersister implements MovieDraftSink
{
    public const SHARP_DROP_THRESHOLD = 3;

    /** @var list<string> movies columns projected from observations */
    private const PROJECTED_FIELDS = ['title_jp', 'title_en', 'release_date', 'runtime_minutes', 'censored', 'maker', 'label', 'series', 'community_score'];

    /** @var array<string, string> movies column => observation field name */
    private const OBSERVATION_FIELD = [
        'title_jp' => 'title_jp',
        'title_en' => 'title_en',
        'release_date' => 'release_date',
        'runtime_minutes' => 'runtime',
        'censored' => 'censored',
        'maker' => 'maker',
        'label' => 'label',
        'series' => 'series',
        'community_score' => 'community_score',
    ];

    public function __construct(
        private readonly MovieMerger $merger,
        private readonly ConflictPolicy $policy,
        private readonly CompletenessTierCalculator $tierCalculator,
        private readonly SourceThrottle $throttle,
        private readonly SourceActivityRecorder $activity,
        private readonly \App\Services\Archive\MongoSiteArchive $archive,
        private readonly \App\Services\Search\ElasticsearchIndexer $searchIndex,
    ) {}

    public function accept(MovieDraft $draft, bool $isRecrawl = false): void
    {
        $this->persist($draft, isRecrawl: $isRecrawl);
    }

    public function persist(MovieDraft $draft, ?int $crawlRunId = null, bool $isRecrawl = false): Movie
    {
        $sharpDrop = false;
        $drop = 0;
        $isNewMovie = false;

        $movie = DB::transaction(function () use ($draft, $crawlRunId, $isRecrawl, &$sharpDrop, &$drop, &$isNewMovie): Movie {
            $crawledAt = $draft->crawledAt ?? CarbonImmutable::now();
            $code = NormalizedCode::from($draft->code);

            $isNewMovie = ! MovieCode::query()->where('code_normalized', $code->value())->exists();
            $movieId = $this->merger->resolveMovieId($draft);

            $this->appendObservations($movieId, $draft, $crawledAt);

            $observations = $this->observationsFor($movieId);
            $movie = Movie::query()->lockForUpdate()->findOrFail($movieId);
            $fieldsBefore = $this->populatedMovieFields($movie);
            $projection = $this->projectCoreFields($movie, $observations);
            $sharpDrop = $projection['sharp_drop'];
            $drop = $projection['drop'];

            $this->upsertCode($movieId, $draft, $code, $crawledAt);
            $this->upsertGenres($movieId, $draft, $crawledAt);
            $this->upsertPerformers($movieId, $draft, $crawledAt);
            $this->upsertMedia($movieId, $draft, $crawledAt);
            $this->upsertCredits($movieId, $draft, $crawledAt);

            $movie->forceFill($projection['values']);

            // Fill-empty denormalized media + description from this draft.
            if ($movie->cover_url === null && $draft->coverUrl !== null) {
                $movie->cover_url = $draft->coverUrl;
            }
            if ($movie->description === null && $draft->description !== null && trim($draft->description) !== '') {
                $movie->description = trim($draft->description);
            }

            $attributes = [
                'completeness_tier' => $this->completenessTier($movie, $observations),
                'updated_at' => $crawledAt,
                'crawled_at' => $crawledAt,
            ];

            if ($movie->attrs === null) {
                $attributes['attrs'] = [];
            }

            if ($projection['sharp_drop']) {
                $attributes['needs_review'] = true;
            }

            if ($this->gainedMovieField($fieldsBefore, $this->populatedMovieFields($movie))) {
                $attributes['recrawl_attempts'] = 0;
                $attributes['recrawl_status'] = null;
            } elseif ($isRecrawl) {
                $maxRounds = max(1, (int) config('javmeta_sources.defaults.recrawl.max_rounds', 3));
                $attempts = min($maxRounds, (int) $movie->recrawl_attempts + 1);
                $attributes['recrawl_attempts'] = $attempts;

                if ($attempts >= $maxRounds) {
                    $attributes['recrawl_status'] = Movie::RECRAWL_STATUS_COMPLETE_AS_POSSIBLE;
                }
            }

            $movie->forceFill($attributes);
            $this->fillSearchVector($movie);
            $movie->save();

            if ($projection['sharp_drop']) {
                $this->flagSharpDrop($movie, $draft, $projection['drop'], $crawledAt);
            }

            if ($crawlRunId !== null) {
                CrawlRun::query()->whereKey($crawlRunId)->increment($isNewMovie ? 'movies_new' : 'movies_updated');
            }

            $this->recordSourceSuccess($draft->sourceSlug);

            return $movie->refresh();
        });

        $this->archiveAndIndex($movie, $draft);

        app(ObservabilityEmitter::class)->emitPersist('movie', [
            'source_slug' => $draft->sourceSlug,
            'is_new' => $isNewMovie,
            'sharp_drop' => $sharpDrop,
            'drop' => $drop,
            'completeness_tier' => $movie->completeness_tier,
        ]);

        return $movie;
    }

    private function archiveAndIndex(Movie $movie, MovieDraft $draft): void
    {
        $payload = [
            'code' => $draft->code,
            'title_jp' => $draft->titleJp,
            'title_en' => $draft->titleEn,
            'cover_url' => $draft->coverUrl,
            'maker' => $draft->maker,
            'label' => $draft->label,
            'series' => $draft->series,
            'genres' => $draft->genres,
            'extras' => $draft->extras,
            'source_url' => $draft->sourceUrl,
        ];

        $mongoId = $this->archive->upsertMovie(
            $draft->sourceSlug,
            $draft->sourceUrl,
            $payload,
            $draft->code,
        );

        if (is_string($mongoId) && $mongoId !== '') {
            \App\Models\MovieSource::query()->updateOrCreate(
                [
                    'site_slug' => $draft->sourceSlug,
                    'mongo_id' => $mongoId,
                ],
                [
                    'movie_id' => $movie->id,
                    'source_url' => $draft->sourceUrl,
                    'source_code' => $draft->code,
                    'crawled_at' => $draft->crawledAt ?? CarbonImmutable::now(),
                ],
            );
        }

        $this->searchIndex->indexMovie($movie->fresh(['genres', 'performers']) ?? $movie);
    }

    private function appendObservations(int $movieId, MovieDraft $draft, CarbonImmutable $crawledAt): void
    {
        foreach ($this->draftObservations($draft) as [$field, $value]) {
            MovieObservation::query()->create([
                'movie_id' => $movieId,
                'field' => $field,
                'value' => $value,
                'value_hash' => hash('sha256', (string) $value),
                'source_slug' => $draft->sourceSlug,
                'source_url' => $draft->sourceUrl,
                'crawled_at' => $crawledAt,
                'is_primary' => false,
            ]);
        }
    }

    /**
     * One observation per core field per crawl, including explicit nulls: the
     * absence of a value on a crawled page is itself crawled data and keeps
     * the projection able to detect a drift (R-4).
     *
     * @return list<array{0: string, 1: string|null}>
     */
    private function draftObservations(MovieDraft $draft): array
    {
        $score = $draft->extras['score'];

        return [
            ['title_jp', $this->nullableTrim($draft->titleJp)],
            ['title_en', $this->nullableTrim($draft->titleEn)],
            ['release_date', $draft->releaseDate?->format('Y-m-d')],
            ['runtime', $draft->runtimeMinutes === null ? null : (string) $draft->runtimeMinutes],
            ['censored', $draft->censored === null ? null : ($draft->censored ? '1' : '0')],
            ['maker', $this->nullableTrim($draft->maker)],
            ['label', $this->nullableTrim($draft->label)],
            ['series', $this->nullableTrim($draft->series)],
            ['cover_url', $this->nullableTrim($draft->coverUrl)],
            ['community_score', $score === null ? null : number_format($score, 2, '.', '')],
        ];
    }

    /** @return EloquentCollection<int, MovieObservation> */
    private function observationsFor(int $movieId): EloquentCollection
    {
        return MovieObservation::query()
            ->where('movie_id', $movieId)
            ->whereIn('field', [...array_values(self::OBSERVATION_FIELD), 'cover_url'])
            ->get();
    }

    /**
     * @param  EloquentCollection<int, MovieObservation>  $observations
     * @return array{values: array<string, mixed>, sharp_drop: bool, drop: int}
     */
    private function projectCoreFields(Movie $movie, EloquentCollection $observations): array
    {
        $current = [];
        $candidate = [];
        $currentNonNull = 0;
        $candidateNonNull = 0;

        foreach (self::PROJECTED_FIELDS as $column) {
            $field = self::OBSERVATION_FIELD[$column];
            $picked = $this->policy->pick($field, $observations->where('field', $field)->values());

            $current[$column] = $movie->getAttribute($column);
            $candidate[$column] = $picked?->value === null
                ? null
                : $this->castObservation($column, (string) $picked->value);

            if ($current[$column] !== null) {
                $currentNonNull++;
            }

            if ($candidate[$column] !== null) {
                $candidateNonNull++;
            }
        }

        $drop = $currentNonNull - $candidateNonNull;

        // SHARP-DROP GUARD (R-4): a projection losing N core fields is treated
        // as source drift, not data loss: keep every current value and flag it.
        if ($drop >= self::SHARP_DROP_THRESHOLD) {
            return ['values' => $current, 'sharp_drop' => true, 'drop' => $drop];
        }

        // NULL-OVERWRITE GUARD (R-4): a null projection never clears a field
        // that currently holds a non-null value.
        foreach (self::PROJECTED_FIELDS as $column) {
            if ($candidate[$column] === null && $current[$column] !== null) {
                $candidate[$column] = $current[$column];
            }
        }

        return ['values' => $candidate, 'sharp_drop' => false, 'drop' => 0];
    }

    private function castObservation(string $column, string $value): mixed
    {
        return match ($column) {
            'runtime_minutes', 'censored' => (int) $value,
            'release_date' => CarbonImmutable::parse($value),
            'community_score' => (float) $value,
            default => $value,
        };
    }

    private function upsertCode(int $movieId, MovieDraft $draft, NormalizedCode $code, CarbonImmutable $crawledAt): void
    {
        MovieCode::query()->updateOrCreate(
            ['code_normalized' => $code->value(), 'source_slug' => $draft->sourceSlug],
            [
                'movie_id' => $movieId,
                'code' => trim($draft->code),
                'kind' => $this->inferCodeKind($code->value()),
                'source_url' => $draft->sourceUrl,
                'crawled_at' => $crawledAt,
            ],
        );
    }

    /**
     * Kind from the code shape only: FC2* => fc2, HEYZO/TOKYO-HOT => uncensored,
     * everything else defaults to dvd. re_release/leak/box_set carry no signal
     * in the code itself and stay dvd for Wave 1.
     */
    private function inferCodeKind(string $codeNormalized): string
    {
        if (str_starts_with($codeNormalized, 'FC2')) {
            return MovieCode::KIND_FC2;
        }

        if (
            str_starts_with($codeNormalized, 'HEYZO')
            || str_starts_with($codeNormalized, 'TOKYOHOT')
            || str_starts_with($codeNormalized, '1PONDO')
            || str_starts_with($codeNormalized, 'CARIBBEANCOM')
        ) {
            return MovieCode::KIND_UNCENSORED;
        }

        return MovieCode::KIND_DVD;
    }

    private function upsertGenres(int $movieId, MovieDraft $draft, CarbonImmutable $crawledAt): void
    {
        foreach ($draft->genres as $label) {
            $normalized = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $label)));

            $genre = Genre::query()->firstOrCreate(
                ['label_normalized' => $normalized],
                ['label_raw' => trim($label)],
            );

            MovieGenre::query()->upsert(
                [
                    [
                        'movie_id' => $movieId,
                        'genre_id' => (int) $genre->id,
                        'source_slug' => $draft->sourceSlug,
                        'crawled_at' => $crawledAt,
                    ],
                ],
                ['movie_id', 'genre_id', 'source_slug'],
                ['crawled_at'],
            );
        }
    }

    private function upsertCredits(int $movieId, MovieDraft $draft, CarbonImmutable $crawledAt): void
    {
        foreach ($draft->directors as $name) {
            $trimmed = trim($name);
            if ($trimmed === '') {
                continue;
            }

            MovieCredit::query()->updateOrCreate(
                [
                    'movie_id' => $movieId,
                    'role' => MovieCredit::ROLE_DIRECTOR,
                    'name' => $trimmed,
                    'site_slug' => $draft->sourceSlug,
                ],
                ['crawled_at' => $crawledAt],
            );
        }
    }

    private function upsertPerformers(int $movieId, MovieDraft $draft, CarbonImmutable $crawledAt): void
    {
        foreach ($draft->performers as $performerDraft) {
            $performer = Performer::query()->updateOrCreate(
                ['source_slug' => $performerDraft->sourceSlug, 'external_id' => $performerDraft->externalId],
                [
                    'name_romaji' => $this->nullableTrim($performerDraft->nameRomaji),
                    'name_kanji' => $this->nullableTrim($performerDraft->nameKanji),
                    'name_kana' => $this->nullableTrim($performerDraft->nameKana),
                    'profile_url' => $this->nullableTrim($performerDraft->profileUrl),
                    'image_url' => $this->nullableTrim($performerDraft->imageUrl),
                    'crawled_at' => $crawledAt,
                ],
            );

            foreach ($performerDraft->aliases as $alias) {
                $trimmed = trim($alias);
                if ($trimmed === '') {
                    continue;
                }

                PerformerAlias::query()->firstOrCreate(
                    ['performer_id' => (int) $performer->id, 'alias' => $trimmed],
                    ['kind' => 'source'],
                );
            }

            MoviePerformer::query()->upsert(
                [
                    [
                        'movie_id' => $movieId,
                        'performer_id' => (int) $performer->id,
                        'source_slug' => $draft->sourceSlug,
                        'crawled_at' => $crawledAt,
                    ],
                ],
                ['movie_id', 'performer_id'],
                ['source_slug', 'crawled_at'],
            );
        }
    }

    private function upsertMedia(int $movieId, MovieDraft $draft, CarbonImmutable $crawledAt): void
    {
        $lists = [
            MovieMedia::KIND_MAGNET => $draft->extras['magnets'],
            MovieMedia::KIND_HLS => $draft->extras['hls'],
            MovieMedia::KIND_GALLERY => $draft->extras['gallery'],
        ];

        foreach ($lists as $kind => $items) {
            foreach ($items as $item) {
                $url = $item['url'] ?? null;
                if (! is_string($url) || trim($url) === '') {
                    continue;
                }

                $meta = $item;
                unset($meta['url']);

                MovieMedia::query()->updateOrCreate(
                    ['movie_id' => $movieId, 'kind' => $kind, 'url' => trim($url)],
                    [
                        'meta' => $meta === [] ? null : $meta,
                        'source_slug' => $draft->sourceSlug,
                        'crawled_at' => $crawledAt,
                    ],
                );
            }
        }
    }

    /** @param EloquentCollection<int, MovieObservation> $observations */
    private function completenessTier(Movie $movie, EloquentCollection $observations): int
    {
        $present = [
            'code' => true,
            'title_jp' => $movie->getAttribute('title_jp') !== null,
            'title_en' => $movie->getAttribute('title_en') !== null,
            'cover_url' => $observations->where('field', 'cover_url')->contains(
                fn(MovieObservation $observation): bool => $observation->value !== null,
            ),
            'release_date' => $movie->getAttribute('release_date') !== null,
            'runtime' => $movie->getAttribute('runtime_minutes') !== null,
            'maker' => $movie->getAttribute('maker') !== null,
            'label' => $movie->getAttribute('label') !== null,
            'series' => $movie->getAttribute('series') !== null,
            'actresses' => MoviePerformer::query()->where('movie_id', $movie->id)->exists(),
            'genres' => MovieGenre::query()->where('movie_id', $movie->id)->exists(),
        ];

        $sourceSlugs = $observations->pluck('source_slug')->unique()->values();
        $originOnly = $sourceSlugs->isNotEmpty()
            && $sourceSlugs->every(fn(mixed $slug): bool => is_string($slug) && $this->tierCalculator->isOriginSource($slug));

        return $this->tierCalculator->tier((int) array_sum($present), $originOnly);
    }

    /** @return array<string, bool> */
    private function populatedMovieFields(Movie $movie): array
    {
        $fields = [];

        foreach ([
            'title_jp', 'title_en', 'description', 'release_date', 'runtime_minutes', 'censored',
            'maker', 'label', 'series', 'community_score', 'cover_url',
        ] as $column) {
            $value = $movie->getAttribute($column);
            $fields[$column] = $value !== null && (! is_string($value) || trim($value) !== '');
        }

        $fields['genres'] = MovieGenre::query()->where('movie_id', $movie->id)->exists();
        $fields['performers'] = MoviePerformer::query()->where('movie_id', $movie->id)->exists();

        return $fields;
    }

    /**
     * @param array<string, bool> $before
     * @param array<string, bool> $after
     */
    private function gainedMovieField(array $before, array $after): bool
    {
        foreach ($after as $field => $populated) {
            if ($populated && ! ($before[$field] ?? false)) {
                return true;
            }
        }

        return false;
    }

    private function flagSharpDrop(Movie $movie, MovieDraft $draft, int $drop, CarbonImmutable $crawledAt): void
    {
        ReviewFlag::query()->create([
            'movie_id' => (int) $movie->id,
            'code_normalized' => $movie->code_normalized,
            'source_slug' => $draft->sourceSlug,
            // A drift flag, not a fetch-failure count (AC-9.3 counts those
            // separately); the magnitude lives in the reason.
            'consecutive_failures' => 0,
            'reason' => "sharp_drop:{$drop}",
            'flagged_at' => $crawledAt,
            'resolved_at' => null,
        ]);
    }

    /**
     * PostgreSQL maintains search_vector as a generated column; other drivers
     * (sqlite test env) need it written explicitly for parity.
     */
    private function fillSearchVector(Movie $movie): void
    {
        if (DB::getDriverName() === 'pgsql') {
            return;
        }

        $tokens = array_filter([
            $movie->getAttribute('title_jp'),
            $movie->getAttribute('title_en'),
            $movie->getAttribute('display_code'),
            $movie->getAttribute('code_normalized'),
        ], fn(mixed $value): bool => is_string($value) && $value !== '');

        $movie->setAttribute('search_vector', implode(' ', $tokens));
    }

    private function recordSourceSuccess(string $sourceSlug): void
    {
        if (! Source::query()->whereKey($sourceSlug)->exists()) {
            return;
        }

        $this->throttle->onSuccess($sourceSlug);
        $this->activity->recordSuccess($sourceSlug);
    }

    private function nullableTrim(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
