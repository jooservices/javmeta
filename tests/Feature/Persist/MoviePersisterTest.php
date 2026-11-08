<?php

declare(strict_types=1);

namespace Tests\Feature\Persist;

use App\Data\Crawl\PerformerDraft;
use App\Data\Crawl\MovieDraft;
use App\Models\CrawlRun;
use App\Models\Genre;
use App\Models\Performer;
use App\Models\ReviewFlag;
use App\Models\Source;
use App\Models\Movie;
use App\Models\MovieCode;
use App\Models\MovieGenre;
use App\Models\MovieMedia;
use App\Models\MovieObservation;
use App\Models\MoviePerformer;
use App\Services\Persist\MoviePersister;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class MoviePersisterTest extends TestCase
{
    use RefreshDatabase;

    public function test_persist_creates_movie_observations_and_code_row(): void
    {
        $crawledAt = CarbonImmutable::parse('2026-09-18 10:00:00');

        $movie = $this->persister()->persist($this->draft([
            'code' => 'SSIS-001',
            'title_jp' => 'Japanese Title',
            'runtime_minutes' => 120,
            'crawled_at' => $crawledAt,
        ]));

        $this->assertSame('SSIS001', $movie->code_normalized);
        $this->assertSame(10, MovieObservation::query()->where('movie_id', $movie->id)->count());
        $this->assertDatabaseHas('movie_observations', ['movie_id' => $movie->id, 'field' => 'title_jp', 'value' => 'Japanese Title']);
        $this->assertDatabaseHas('movie_observations', ['movie_id' => $movie->id, 'field' => 'runtime', 'value' => '120']);
        $this->assertDatabaseHas('movie_codes', ['movie_id' => $movie->id, 'code_normalized' => 'SSIS001', 'code' => 'SSIS-001', 'source_slug' => 'javdb']);
        $this->assertSame('2026-09-18 10:00:00', CarbonImmutable::parse($movie->crawled_at)->format('Y-m-d H:i:s'));
    }

    public function test_accept_is_the_sink_seam_and_persists(): void
    {
        $sink = $this->persister();

        $sink->accept($this->draft(['code' => 'SSIS-001', 'title_jp' => 'Via Sink']));

        $this->assertSame(1, Movie::query()->count());
        $this->assertDatabaseHas('movie_observations', ['field' => 'title_jp', 'value' => 'Via Sink']);
    }

    public function test_successful_recrawls_without_new_fields_stop_at_the_configured_maximum(): void
    {
        config()->set('javmeta_sources.defaults.recrawl.max_rounds', 3);
        $persister = $this->persister();
        $draft = $this->draft(['code' => 'SSIS-001']);
        $movie = $persister->persist($draft);

        foreach ([1, 2, 3] as $round) {
            $movie = $persister->persist($draft, isRecrawl: true);

            $this->assertSame($round, $movie->recrawl_attempts);
        }

        $this->assertSame(Movie::RECRAWL_STATUS_COMPLETE_AS_POSSIBLE, $movie->recrawl_status);
    }

    public function test_gaining_a_new_field_resets_the_recrawl_count_and_stopped_status(): void
    {
        $persister = $this->persister();
        $draft = $this->draft(['code' => 'SSIS-001', 'title_jp' => 'Japanese Title']);
        $movie = $persister->persist($draft);
        $movie->forceFill([
            'recrawl_attempts' => 2,
            'recrawl_status' => Movie::RECRAWL_STATUS_COMPLETE_AS_POSSIBLE,
        ])->save();

        $movie = $persister->persist(
            $this->draft(['code' => 'SSIS-001', 'title_jp' => 'Japanese Title', 'title_en' => 'English Title']),
            isRecrawl: true,
        );

        $this->assertSame(0, $movie->recrawl_attempts);
        $this->assertNull($movie->recrawl_status);
    }

    public function test_re_persist_is_append_only_and_never_updates_old_observations(): void
    {
        $first = $this->persister()->persist($this->draft(['code' => 'SSIS-001', 'title_jp' => 'First Title']));
        $original = MovieObservation::query()->where('movie_id', $first->id)->get()->keyBy('id');

        $this->persister()->persist($this->draft(['code' => 'SSIS-001', 'title_jp' => 'First Title']));

        $this->assertSame(20, MovieObservation::query()->where('movie_id', $first->id)->count());

        foreach ($original as $id => $observation) {
            $fresh = MovieObservation::query()->findOrFail($id);
            $this->assertSame($observation->field, $fresh->field);
            $this->assertSame($observation->value, $fresh->value);
            $this->assertSame($observation->value_hash, $fresh->value_hash);
            $this->assertSame($observation->source_slug, $fresh->source_slug);
        }

        $this->assertSame(1, MovieCode::query()->where('code_normalized', 'SSIS001')->count());
    }

    public function test_null_overwrite_guard_keeps_existing_non_null_value(): void
    {
        $this->persister()->persist($this->draft([
            'code' => 'SSIS-001',
            'title_jp' => 'Original Title',
            'title_en' => 'English Title',
            'release_date' => CarbonImmutable::parse('2024-05-15'),
            'runtime_minutes' => 120,
            'maker' => 'Maker',
            'label' => 'Label',
            'series' => 'Series',
            'censored' => true,
        ]));

        $this->persister()->persist($this->draft([
            'code' => 'SSIS-001',
            'title_jp' => null,
            'title_en' => 'English Title',
            'release_date' => CarbonImmutable::parse('2024-05-15'),
            'runtime_minutes' => 120,
            'maker' => 'Maker',
            'label' => 'Label',
            'series' => 'Series',
            'censored' => true,
        ]));

        $movie = Movie::query()->where('code_normalized', 'SSIS001')->firstOrFail();
        $this->assertSame('Original Title', $movie->title_jp);
        $this->assertSame('English Title', $movie->title_en);
        $this->assertFalse($movie->needs_review);
        $this->assertSame(0, ReviewFlag::query()->count());
    }

    public function test_sharp_drop_guard_keeps_old_values_flags_review_and_creates_review_flag(): void
    {
        $first = $this->persister()->persist($this->draft([
            'code' => 'SSIS-001',
            'title_jp' => 'Original Title',
            'title_en' => 'English Title',
            'release_date' => CarbonImmutable::parse('2024-05-15'),
            'runtime_minutes' => 120,
            'censored' => true,
            'maker' => 'Maker',
            'label' => 'Label',
            'series' => 'Series',
            'cover_url' => 'https://javdb.test/covers/1.jpg',
            'extras' => ['magnets' => [], 'hls' => [], 'gallery' => [], 'score' => 8.4],
        ]));

        // Drifted re-crawl from the same source: only the title survives.
        $this->persister()->persist($this->draft([
            'code' => 'SSIS-001',
            'title_jp' => 'Sparse Title',
        ]));

        $movie = Movie::query()->findOrFail($first->id);
        $this->assertSame('Original Title', $movie->title_jp);
        $this->assertSame('Maker', $movie->maker);
        $this->assertSame(120, $movie->runtime_minutes);
        $this->assertTrue($movie->needs_review);

        $flag = ReviewFlag::query()->firstOrFail();
        $this->assertSame((int) $movie->id, (int) $flag->movie_id);
        $this->assertSame('SSIS001', $flag->code_normalized);
        $this->assertSame('javdb', $flag->source_slug);
        $this->assertSame('sharp_drop:8', $flag->reason);
    }

    public function test_genres_union_by_normalized_label_with_per_source_provenance(): void
    {
        $movie = $this->persister()->persist($this->draft([
            'code' => 'SSIS-001',
            'genres' => ['Drama', 'Big Ass'],
        ]));

        $this->persister()->persist($this->draft([
            'code' => 'SSIS001',
            'source_slug' => 'onejav',
            'genres' => ['drama', 'Big Ass', 'Anal'],
        ]));

        $this->assertSame(3, Genre::query()->count());
        $this->assertSame(5, MovieGenre::query()->count());

        $drama = Genre::query()->where('label_normalized', 'drama')->firstOrFail();
        $this->assertDatabaseHas('movie_genres', ['movie_id' => $movie->id, 'genre_id' => $drama->id, 'source_slug' => 'javdb']);
        $this->assertDatabaseHas('movie_genres', ['movie_id' => $movie->id, 'genre_id' => $drama->id, 'source_slug' => 'onejav']);
        $this->assertDatabaseHas('genres', ['label_normalized' => 'big ass']);
    }

    public function test_media_is_url_reference_only_and_deduplicated_by_movie_kind_url(): void
    {
        $movie = $this->persister()->persist($this->draft([
            'code' => 'SSIS-001',
            'extras' => [
                'magnets' => [['url' => 'magnet:?xt=urn:btih:aaa']],
                'hls' => [],
                'gallery' => [],
                'score' => null,
            ],
        ]));

        $this->persister()->persist($this->draft([
            'code' => 'SSIS001',
            'source_slug' => 'onejav',
            'extras' => [
                'magnets' => [['url' => 'magnet:?xt=urn:btih:aaa'], ['url' => 'magnet:?xt=urn:btih:bbb']],
                'hls' => [],
                'gallery' => [],
                'score' => null,
            ],
        ]));

        $this->assertSame(2, MovieMedia::query()->count());
        $this->assertDatabaseHas('movie_media', ['movie_id' => $movie->id, 'kind' => 'magnet', 'url' => 'magnet:?xt=urn:btih:aaa']);
        $this->assertDatabaseHas('movie_media', ['movie_id' => $movie->id, 'kind' => 'magnet', 'url' => 'magnet:?xt=urn:btih:bbb']);

        foreach (MovieMedia::query()->get() as $media) {
            $this->assertNull($media->meta);
            $this->assertSame($movie->id, $media->movie_id);
        }
    }

    public function test_media_meta_is_stored_as_jsonb_without_the_url(): void
    {
        $movie = $this->persister()->persist($this->draft([
            'code' => 'SSIS-001',
            'extras' => [
                'magnets' => [],
                'hls' => [['url' => 'https://cdn.test/stream.m3u8', 'video_id' => 'v42']],
                'gallery' => [],
                'score' => null,
            ],
        ]));

        $media = MovieMedia::query()->where('movie_id', $movie->id)->where('kind', 'hls')->firstOrFail();
        $this->assertSame('https://cdn.test/stream.m3u8', $media->url);
        $this->assertSame(['video_id' => 'v42'], $media->meta);
    }

    public function test_performers_are_identified_per_source_and_external_id(): void
    {
        $movie = $this->persister()->persist($this->draft([
            'code' => 'SSIS-001',
            'performers' => [PerformerDraft::from('javdb', 'p1', 'Same Name')],
        ]));

        $this->persister()->persist($this->draft([
            'code' => 'SSIS001',
            'source_slug' => 'onejav',
            'performers' => [PerformerDraft::from('onejav', 'p1', 'Same Name')],
        ]));

        $this->persister()->persist($this->draft([
            'code' => 'SSIS001',
            'source_slug' => 'onejav',
            'performers' => [PerformerDraft::from('onejav', 'p2', 'Other Name')],
        ]));

        $this->assertSame(3, Performer::query()->count());
        $this->assertSame(3, MoviePerformer::query()->count());
        $this->assertDatabaseHas('performers', ['source_slug' => 'javdb', 'external_id' => 'p1']);
        $this->assertDatabaseHas('performers', ['source_slug' => 'onejav', 'external_id' => 'p1']);
    }

    public function test_re_persist_same_movie_and_performer_does_not_throw(): void
    {
        $base = [
            'code' => 'SSIS-001',
            'performers' => [PerformerDraft::from('javdb', 'p1', 'Same Name')],
        ];

        $movie = $this->persister()->persist($this->draft($base));
        $this->persister()->persist($this->draft($base + [
            'crawled_at' => CarbonImmutable::parse('2026-09-18 10:30:00'),
        ]));

        $this->assertSame(1, Performer::query()->count());
        $this->assertSame(1, MoviePerformer::query()->count());
        $this->assertDatabaseHas('movie_performers', ['movie_id' => $movie->id]);
        $this->assertSame('2026-09-18 10:30:00', MoviePerformer::query()->firstOrFail()->crawled_at?->format('Y-m-d H:i:s'));
    }

    public function test_re_persist_same_movie_and_genre_does_not_throw(): void
    {
        $base = [
            'code' => 'SSIS-001',
            'genres' => ['Drama'],
        ];

        $movie = $this->persister()->persist($this->draft($base));
        $this->persister()->persist($this->draft($base + [
            'crawled_at' => CarbonImmutable::parse('2026-09-18 10:30:00'),
        ]));

        $this->assertSame(1, Genre::query()->count());
        $this->assertSame(1, MovieGenre::query()->count());
        $this->assertDatabaseHas('movie_genres', ['movie_id' => $movie->id]);
    }

    public function test_performer_aliases_are_created(): void
    {
        $movie = $this->persister()->persist($this->draft([
            'code' => 'SSIS-001',
            'performers' => [PerformerDraft::from('javdb', 'p1', 'Name A', null, null, ['Alias One', 'Alias Two'])],
        ]));

        $performerId = MoviePerformer::query()->where('movie_id', $movie->id)->value('performer_id');
        $performer = Performer::query()->findOrFail((int) $performerId);

        $this->assertDatabaseHas('performer_aliases', ['performer_id' => $performer->id, 'alias' => 'Alias One']);
        $this->assertDatabaseHas('performer_aliases', ['performer_id' => $performer->id, 'alias' => 'Alias Two']);
    }

    public function test_completeness_tier_reflects_core_field_presence(): void
    {
        $sparse = $this->persister()->persist($this->draft(['code' => 'SSIS-001', 'title_jp' => 'Only Title']));
        $this->assertSame(0, $sparse->completeness_tier);

        $rich = $this->persister()->persist($this->draft([
            'code' => 'ABC-123',
            'title_jp' => 'Japanese Title',
            'title_en' => 'English Title',
            'release_date' => CarbonImmutable::parse('2024-05-15'),
            'runtime_minutes' => 120,
            'censored' => true,
            'maker' => 'Maker',
            'label' => 'Label',
            'series' => 'Series',
            'cover_url' => 'https://javdb.test/covers/2.jpg',
            'genres' => ['Drama'],
            'extras' => ['magnets' => [], 'hls' => [], 'gallery' => [], 'score' => 8.4],
        ]));

        $this->assertSame(3, $rich->completeness_tier);
    }

    public function test_origin_source_class_factor_bumps_otherwise_rich_records(): void
    {
        $movie = $this->persister()->persist($this->draft([
            'code' => 'FC2-1234567',
            'source_slug' => 'fc2',
            'title_jp' => 'FC2 Title',
            'title_en' => 'FC2 English',
            'release_date' => CarbonImmutable::parse('2024-05-15'),
            'runtime_minutes' => 60,
            'censored' => false,
            'cover_url' => 'https://fc2.test/cover.jpg',
        ]));

        // 6 present fields (code, title_jp, title_en, cover_url, release_date,
        // runtime) from an origin-only source => tier 2 + 1 factor = 3.
        $this->assertSame(3, $movie->completeness_tier);
    }

    public function test_null_fields_stay_null_and_never_become_empty_strings(): void
    {
        $movie = $this->persister()->persist($this->draft([
            'code' => 'SSIS-001',
            'title_jp' => '   ', // whitespace only -> null, never ''
        ]));

        $this->assertNull($movie->title_jp);
        $this->assertNull($movie->title_en);
        $this->assertNull($movie->maker);
        $this->assertDatabaseHas('movie_observations', ['movie_id' => $movie->id, 'field' => 'title_jp', 'value' => null]);
        $this->assertDatabaseMissing('movie_observations', ['movie_id' => $movie->id, 'field' => 'title_jp', 'value' => '']);
    }

    public function test_code_kind_is_inferred_from_the_code_shape(): void
    {
        $fc2 = $this->persister()->persist($this->draft(['code' => 'FC2-1234567', 'title_jp' => 'T']));
        $uncensored = $this->persister()->persist($this->draft(['code' => 'HEYZO-001', 'title_jp' => 'T']));
        $onepondo = $this->persister()->persist($this->draft(['code' => '1PONDO-060426_001', 'title_jp' => 'T']));
        $caribbean = $this->persister()->persist($this->draft(['code' => 'CARIBBEANCOM-080826-001', 'title_jp' => 'T']));
        $dvd = $this->persister()->persist($this->draft(['code' => 'SSIS-001', 'title_jp' => 'T']));

        $this->assertDatabaseHas('movie_codes', ['movie_id' => $fc2->id, 'code_normalized' => 'FC21234567', 'kind' => 'fc2']);
        $this->assertDatabaseHas('movie_codes', ['movie_id' => $uncensored->id, 'code_normalized' => 'HEYZO001', 'kind' => 'uncensored']);
        $this->assertDatabaseHas('movie_codes', ['movie_id' => $onepondo->id, 'code_normalized' => '1PONDO060426001', 'kind' => 'uncensored']);
        $this->assertDatabaseHas('movie_codes', ['movie_id' => $caribbean->id, 'code_normalized' => 'CARIBBEANCOM080826001', 'kind' => 'uncensored']);
        $this->assertDatabaseHas('movie_codes', ['movie_id' => $dvd->id, 'code_normalized' => 'SSIS001', 'kind' => 'dvd']);
    }

    public function test_crawl_run_counters_are_bumped_when_run_id_is_given(): void
    {
        $run = CrawlRun::query()->create([
            'source_slug' => 'javdb',
            'started_at' => now(),
            'status' => 'running',
            'pages_fetched' => 0,
            'movies_new' => 0,
            'movies_updated' => 0,
            'failures' => 0,
            'proxy_requests' => 0,
            'proxy_bytes' => 0,
            'browser_fetches' => 0,
        ]);

        $this->persister()->persist($this->draft(['code' => 'SSIS-001', 'title_jp' => 'T']), (int) $run->id);
        $this->persister()->persist($this->draft(['code' => 'SSIS001', 'source_slug' => 'onejav', 'title_jp' => 'T']), (int) $run->id);

        $run->refresh();
        $this->assertSame(1, $run->movies_new);
        $this->assertSame(1, $run->movies_updated);
    }

    public function test_crawl_run_counters_skip_missing_run_gracefully(): void
    {
        $this->persister()->persist($this->draft(['code' => 'SSIS-001', 'title_jp' => 'T']), 999999);

        $this->assertSame(1, Movie::query()->count());
    }

    public function test_source_throttle_and_circuit_breaker_reward_success_when_source_row_exists(): void
    {
        $source = Source::factory()->create([
            'slug' => 'javdb',
            'gap_seconds_default' => 20.0,
            'gap_seconds_min' => 10.0,
            'gap_seconds_max' => 60.0,
            'gap_seconds_current' => 20.0,
        ]);

        $this->persister()->persist($this->draft(['code' => 'SSIS-001', 'title_jp' => 'T']));

        $source->refresh();
        $this->assertLessThan(20.0, (float) $source->gap_seconds_current);
        $this->assertNotNull($source->last_success_at);
    }

    public function test_variants_from_two_sources_resolve_to_one_movie_row(): void
    {
        $this->persister()->persist($this->draft(['code' => 'SSIS-001', 'title_jp' => 'From JavDB']));
        $this->persister()->persist($this->draft(['code' => 'ssis-1', 'source_slug' => 'onejav', 'title_jp' => 'From OneJav']));

        $this->assertSame(1, Movie::query()->count());
        $this->assertSame(2, MovieCode::query()->count());
        $movie = Movie::query()->firstOrFail();
        $this->assertDatabaseHas('movie_observations', ['movie_id' => $movie->id, 'field' => 'title_jp', 'value' => 'From OneJav', 'source_slug' => 'onejav']);
    }

    private function persister(): MoviePersister
    {
        return $this->app->make(MoviePersister::class);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function draft(array $overrides = []): MovieDraft
    {
        return MovieDraft::from(
            sourceSlug: (string) ($overrides['source_slug'] ?? 'javdb'),
            sourceUrl: (string) ($overrides['source_url'] ?? 'https://javdb.test/v/1'),
            code: (string) ($overrides['code'] ?? 'SSIS-001'),
            titleJp: $overrides['title_jp'] ?? null,
            titleEn: $overrides['title_en'] ?? null,
            releaseDate: $overrides['release_date'] ?? null,
            runtimeMinutes: $overrides['runtime_minutes'] ?? null,
            censored: $overrides['censored'] ?? null,
            maker: $overrides['maker'] ?? null,
            label: $overrides['label'] ?? null,
            series: $overrides['series'] ?? null,
            genres: $overrides['genres'] ?? [],
            performers: $overrides['performers'] ?? [],
            coverUrl: $overrides['cover_url'] ?? null,
            extras: $overrides['extras'] ?? ['magnets' => [], 'hls' => [], 'gallery' => [], 'score' => null],
            crawledAt: $overrides['crawled_at'] ?? CarbonImmutable::parse('2026-09-18 09:00:00'),
        );
    }
}
