<?php

declare(strict_types=1);

namespace App\Services\Persist;

use App\Repositories\CrawlRunRepository;
use App\Repositories\MovieRepository;
use App\Repositories\MovieSourceRepository;
use App\Data\Crawl\MovieDraft;
use App\Data\Crawl\PerformerDraft;
use App\Data\Persist\MoviePersistOutcome;
use App\Jobs\IndexMovieJob;
use App\Models\Genre;
use App\Models\Performer;
use App\Models\PerformerAlias;
use App\Models\PerformerSource;
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
use App\Services\Identity\PerformerIdentityService;
use App\Services\Crawl\SourceThrottle;
use App\Services\Crawl\MovieDraftSink;
use App\Services\Merge\ConflictPolicy;
use App\Services\Merge\MovieMerger;
use App\Services\Normalize\PerformerAttributeNormalizer;
use App\Support\Code\NormalizedCode;
use App\Observability\ObservabilityEmitter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

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

    private readonly PerformerAttributeNormalizer $attributeNormalizer;

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
        private readonly PerformerIdentityService $identity,
        ?PerformerAttributeNormalizer $attributeNormalizer = null,
    ) {
        $this->attributeNormalizer = $attributeNormalizer ?? new PerformerAttributeNormalizer();
    }

    public function accept(MovieDraft $draft, bool $isRecrawl = false): void
    {
        $this->persist($draft, isRecrawl: $isRecrawl);
    }

    public function persist(MovieDraft $draft, ?int $crawlRunId = null, bool $isRecrawl = false): Movie
    {
        $mongoId = $this->archive($draft);

        $outcome = app(MovieRepository::class)->transaction(
            fn(): MoviePersistOutcome => $this->persistWithinTransaction($draft, $crawlRunId, $isRecrawl, $mongoId),
        );

        $this->publishPersistedMovie($outcome, $draft);

        return $outcome->movie;
    }

    private function persistWithinTransaction(MovieDraft $draft, ?int $crawlRunId, bool $isRecrawl, ?string $mongoId): MoviePersistOutcome
    {
        $crawledAt = $draft->crawledAt ?? CarbonImmutable::now();
        $code = NormalizedCode::from($draft->code);

        $isNewMovie = ! app(MovieRepository::class)->modelQuery(MovieCode::class)->where('code_normalized', $code->value())->exists();
        $movieId = $this->merger->resolveMovieId($draft);

        $this->appendObservations($movieId, $draft, $crawledAt);

        $observations = $this->observationsFor($movieId);
        $movie = app(MovieRepository::class)->modelQuery(Movie::class)->lockForUpdate()->findOrFail($movieId);
        $fieldsBefore = $this->populatedMovieFields($movie);
        $projection = $this->projectCoreFields($movie, $observations);

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

        $attributes = array_merge($attributes, $this->recrawlAttributes($movie, $fieldsBefore, $isRecrawl));

        $movie->forceFill($attributes);
        $this->fillSearchVector($movie);
        $movie->save();

        if ($projection['sharp_drop']) {
            $this->flagSharpDrop($movie, $draft, $projection['drop'], $crawledAt);
        }

        if ($crawlRunId !== null) {
            app(CrawlRunRepository::class)->incrementMovieCount($crawlRunId, $isNewMovie);
        }

        app(MovieSourceRepository::class)->recordDraft($movieId, $draft, $mongoId);
        $this->recordSourceSuccess($draft->sourceSlug);

        return new MoviePersistOutcome($movie->refresh(), $isNewMovie, $projection['sharp_drop'], $projection['drop']);
    }

    private function publishPersistedMovie(MoviePersistOutcome $outcome, MovieDraft $draft): void
    {
        $this->dispatchIndex($outcome->movie);
        app(ObservabilityEmitter::class)->emitPersist('movie', [
            'source_slug' => $draft->sourceSlug,
            'movie_code' => $outcome->movie->display_code,
            'is_new' => $outcome->isNewMovie,
            'sharp_drop' => $outcome->sharpDrop,
            'drop' => $outcome->drop,
            'completeness_tier' => $outcome->movie->completeness_tier,
        ]);
    }

    /** @param array<string, mixed> $fieldsBefore
     *  @return array<string, mixed>
     */
    private function recrawlAttributes(Movie $movie, array $fieldsBefore, bool $isRecrawl): array
    {
        if ($this->gainedMovieField($fieldsBefore, $this->populatedMovieFields($movie))) {
            return ['recrawl_attempts' => 0, 'recrawl_status' => null];
        }
        if (! $isRecrawl) {
            return [];
        }

        $maxRounds = max(1, (int) config('javmeta_sources.defaults.recrawl.max_rounds', 3));
        $attempts = min($maxRounds, (int) $movie->recrawl_attempts + 1);
        $attributes = ['recrawl_attempts' => $attempts];
        if ($attempts >= $maxRounds) {
            $attributes['recrawl_status'] = Movie::RECRAWL_STATUS_COMPLETE_AS_POSSIBLE;
        }

        return $attributes;
    }

    private function dispatchIndex(Movie $movie): void
    {
        if (! (bool) config('elasticsearch.enabled', true)) {
            return;
        }

        IndexMovieJob::dispatch((int) $movie->getKey())
            ->onQueue((string) config('javmeta_queues.search_queue', 'search'))
            ->afterCommit();
    }

    private function archive(MovieDraft $draft): ?string
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

        return is_string($mongoId) && $mongoId !== '' ? $mongoId : null;
    }

    private function appendObservations(int $movieId, MovieDraft $draft, CarbonImmutable $crawledAt): void
    {
        foreach ($this->draftObservations($draft) as [$field, $value]) {
            app(MovieRepository::class)->modelQuery(MovieObservation::class)->create([
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
        return app(MovieRepository::class)->modelQuery(MovieObservation::class)
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
        app(MovieRepository::class)->modelQuery(MovieCode::class)->updateOrCreate(
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

            $genre = app(MovieRepository::class)->modelQuery(Genre::class)->firstOrCreate(
                ['label_normalized' => $normalized],
                ['label_raw' => trim($label)],
            );

            app(MovieRepository::class)->modelQuery(MovieGenre::class)->upsert(
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

            app(MovieRepository::class)->modelQuery(MovieCredit::class)->updateOrCreate(
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
            $performerDraft = $this->attributeNormalizer->normalizeDraft($performerDraft);
            $previous = app(MovieRepository::class)->modelQuery(PerformerSource::class)->firstOrNew([
                'site_slug' => $performerDraft->sourceSlug,
                'external_id' => $performerDraft->externalId,
            ]);
            $existing = app(MovieRepository::class)->modelQuery(Performer::class)->where('source_slug', $performerDraft->sourceSlug)
                ->where('external_id', $performerDraft->externalId)->first();
            $existingProfile = $this->normalizedExistingPerformerProfile($existing, $performerDraft->sourceSlug);
            $performer = app(MovieRepository::class)->modelQuery(Performer::class)->updateOrCreate(
                ['source_slug' => $performerDraft->sourceSlug, 'external_id' => $performerDraft->externalId],
                $this->performerAttributes($performerDraft, $existingProfile, $crawledAt),
            );

            $this->identity->preserveRenamedSourceNames(
                $previous,
                $performer,
                [$performerDraft->nameRomaji, $performerDraft->nameKanji, $performerDraft->nameKana],
                $performerDraft->sourceSlug,
            );
            $source = $previous;
            $source->fill([
                'performer_id' => $performer->canonical_performer_id ?? $performer->id,
                'source_performer_id' => $performer->id,
                'mongo_id' => $source->mongo_id ?: ('local:' . $performer->id),
                'source_url' => $performerDraft->profileUrl ?? $source->source_url,
                'name_romaji' => $performerDraft->nameRomaji ?? $source->name_romaji,
                'name_kanji' => $performerDraft->nameKanji ?? $source->name_kanji,
                'name_kana' => $performerDraft->nameKana ?? $source->name_kana,
                'birth_date' => $performerDraft->birthDate ?? $this->attributeNormalizer->date(
                    'birth_date',
                    $source->getAttribute('birth_date'),
                    $performerDraft->sourceSlug,
                    false,
                ),
                'cross_link_source' => $performerDraft->crossLinkId === null
                    ? $source->cross_link_source
                    : ($performerDraft->crossLinkSource ?? $performerDraft->sourceSlug),
                'cross_link_id' => $performerDraft->crossLinkId ?? $source->cross_link_id,
                'removed_at' => null,
                'crawled_at' => $crawledAt,
            ]);
            $source->save();

            foreach ($performerDraft->aliases as $alias) {
                $trimmed = trim($alias);
                if ($trimmed === '') {
                    continue;
                }

                app(MovieRepository::class)->modelQuery(PerformerAlias::class)->firstOrCreate(
                    [
                        'performer_id' => (int) ($performer->canonical_performer_id ?? $performer->id),
                        'alias' => $trimmed,
                        'source_slug' => $performerDraft->sourceSlug,
                        'source_performer_id' => (int) $performer->id,
                    ],
                    ['kind' => 'source'],
                );
            }

            $this->identity->refreshNameKeys($source);

            // Preserve each original source credit before reconciling the complete cast.
            app(MovieRepository::class)->modelQuery(MoviePerformer::class)->upsert(
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
        $this->reconcileMovieCast($movieId, $draft, $crawledAt);
    }

    /** @return array<string, mixed> */
    private function normalizedExistingPerformerProfile(?Performer $performer, string $sourceSlug): array
    {
        if ($performer === null) {
            return [];
        }

        return $this->attributeNormalizer->normalizeStoredAttributes([
            'birth_date' => $performer->getAttribute('birth_date'),
            'height_cm' => $performer->getAttribute('height_cm'),
            'bust' => $performer->getAttribute('bust'),
            'waist' => $performer->getAttribute('waist'),
            'hip' => $performer->getAttribute('hip'),
            'cup' => $performer->getAttribute('cup'),
            'blood_type' => $performer->getAttribute('blood_type'),
            'blood_rh' => $performer->getAttribute('blood_rh'),
            'debut_date' => $performer->getAttribute('debut_date'),
            'location' => $performer->getAttribute('location'),
        ], $sourceSlug, false);
    }

    /** @param array<string, mixed> $existingProfile
     *  @return array<string, mixed>
     */
    private function performerAttributes(PerformerDraft $draft, array $existingProfile, CarbonImmutable $crawledAt): array
    {
        return [
            'name_romaji' => $this->nullableTrim($draft->nameRomaji),
            'name_kanji' => $this->nullableTrim($draft->nameKanji),
            'name_kana' => $this->nullableTrim($draft->nameKana),
            'profile_url' => $this->nullableTrim($draft->profileUrl),
            'image_url' => $this->nullableTrim($draft->imageUrl),
            'birth_date' => $draft->birthDate ?? ($existingProfile['birth_date'] ?? null),
            'height_cm' => $draft->heightCm ?? ($existingProfile['height_cm'] ?? null),
            'bust' => $draft->bust ?? ($existingProfile['bust'] ?? null),
            'waist' => $draft->waist ?? ($existingProfile['waist'] ?? null),
            'hip' => $draft->hip ?? ($existingProfile['hip'] ?? null),
            'cup' => $draft->cup ?? ($existingProfile['cup'] ?? null),
            'blood_type' => $draft->bloodType ?? ($existingProfile['blood_type'] ?? null),
            'blood_rh' => $draft->bloodRh ?? ($existingProfile['blood_rh'] ?? null),
            'debut_date' => $draft->debutDate ?? ($existingProfile['debut_date'] ?? null),
            'location' => $draft->location ?? ($existingProfile['location'] ?? null),
            'crawled_at' => $crawledAt,
        ];
    }

    private function reconcileMovieCast(int $movieId, MovieDraft $draft, CarbonImmutable $crawledAt): void
    {
        foreach ($draft->performers as $performerDraft) {
            $source = app(MovieRepository::class)->modelQuery(PerformerSource::class)->where('site_slug', $performerDraft->sourceSlug)
                ->where('external_id', $performerDraft->externalId)->firstOrFail();
            $canonicalId = $this->identity->reconcileAndSync($source);
            app(MovieRepository::class)->modelQuery(MoviePerformer::class)->insertOrIgnore([
                ['movie_id' => $movieId, 'performer_id' => $canonicalId,
                    'source_slug' => $draft->sourceSlug, 'crawled_at' => $crawledAt],
            ]);
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

                app(MovieRepository::class)->modelQuery(MovieMedia::class)->updateOrCreate(
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
            'actresses' => app(MovieRepository::class)->modelQuery(MoviePerformer::class)->where('movie_id', $movie->id)->exists(),
            'genres' => app(MovieRepository::class)->modelQuery(MovieGenre::class)->where('movie_id', $movie->id)->exists(),
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

        $fields['genres'] = app(MovieRepository::class)->modelQuery(MovieGenre::class)->where('movie_id', $movie->id)->exists();
        $fields['performers'] = app(MovieRepository::class)->modelQuery(MoviePerformer::class)->where('movie_id', $movie->id)->exists();

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
        app(MovieRepository::class)->modelQuery(ReviewFlag::class)->create([
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
        if (app(MovieRepository::class)->driverName() === 'pgsql') {
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
        if (! app(MovieRepository::class)->modelQuery(Source::class)->whereKey($sourceSlug)->exists()) {
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
