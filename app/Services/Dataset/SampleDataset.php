<?php

declare(strict_types=1);

namespace App\Services\Dataset;

use App\Repositories\MovieRepository;
use App\Models\Movie;
use App\Models\Performer;
use App\Services\Search\ElasticsearchIndexer;
use App\Support\Code\NormalizedCode;
use Illuminate\Database\Query\Builder;
use RuntimeException;

/**
 * A repeatable fake collection for tests and UAT: 520 movies, 210 performers, 120 genres, 20 makers, 10 series, with the
 * links between them and the awkward cases the screens must survive. Everything is generated (no scraped text, no
 * images, no real people). Explicit ownership in sample_dataset_rows makes loading repeatable and limits wipe to
 * owned parents and their dependent rows. Genre count is >100 so browse/facet paging is exercised.
 */
final class SampleDataset
{
    public const SOURCE = 'sample';
    public const MOVIES = 520;
    public const PERFORMERS = 210;
    public const GENRES = 120;
    public const MAKERS = 20;
    public const SERIES = 10;

    private const GIVEN = ['Aiko', 'Mio', 'Rin', 'Yui', 'Hana', 'Nana', 'Saki', 'Mai', 'Emi', 'Kana', 'Riko', 'Sora', 'Yuna', 'Miku', 'Airi'];
    private const FAMILY = ['Sato', 'Suzuki', 'Takahashi', 'Tanaka', 'Ito', 'Watanabe', 'Yamamoto', 'Nakamura', 'Kobayashi', 'Kato', 'Yoshida', 'Hayashi'];
    private const KANJI = ['愛', '美', '優', '花', '菜', '咲', '舞', '恵', '奈', '空'];
    private const BLOOD = ['A', 'B', 'O', 'AB'];
    private const CUPS = ['B', 'C', 'D', 'E', 'F', 'G'];
    private const CITIES = ['Tokyo', 'Osaka', 'Nagoya', 'Fukuoka', 'Sapporo', 'Kyoto', 'Kobe'];

    public function __construct(private readonly ?ElasticsearchIndexer $indexer = null) {}

    /** @return array{movies: int, performers: int, genres: int, makers: int, series: int, indexed: int} */
    public function load(bool $index = false): array
    {
        $now = now();
        app(MovieRepository::class)->transaction(function () use ($now): void {
            $this->loadGenres();
            $this->loadPerformers($now);
            $this->loadMovies($now);
        });
        app(\App\Services\Api\ResponseCacheEpoch::class)->bump();

        $indexed = $index ? $this->index() : 0;

        return $this->counts() + ['indexed' => $indexed];
    }

    /** @return array{movies: int, performers: int, genres: int, makers: int, series: int} the rows that the sample owns */
    public function counts(): array
    {
        return [
            'movies' => (int) $this->owned('movies', 'movie_id')->count(),
            'performers' => (int) $this->owned('performers', 'performer_id')->count(),
            'genres' => (int) $this->owned('genres', 'genre_id')->count(),
            'makers' => (int) $this->owned('movies', 'movie_id')->distinct()->count('maker'),
            'series' => (int) $this->owned('movies', 'movie_id')->whereNotNull('series')->distinct()->count('series'),
        ];
    }

    /** Remove exactly the rows the load created. */
    public function wipe(): void
    {
        app(MovieRepository::class)->transaction(function (): void {
            $movieIds = $this->owned('movies', 'movie_id')->pluck('id')->all();
            $performerIds = $this->owned('performers', 'performer_id')->pluck('id')->all();
            $genreIds = $this->owned('genres', 'genre_id')->pluck('id')->all();

            foreach (array_chunk($movieIds, 500) as $chunk) {
                app(MovieRepository::class)->tableQuery('movie_genres')->whereIn('movie_id', $chunk)->delete();
                app(MovieRepository::class)->tableQuery('movie_performers')->whereIn('movie_id', $chunk)->delete();
                app(MovieRepository::class)->tableQuery('movie_observations')->whereIn('movie_id', $chunk)->delete();
                app(MovieRepository::class)->tableQuery('movie_codes')->whereIn('movie_id', $chunk)->delete();
                app(MovieRepository::class)->tableQuery('movie_media')->whereIn('movie_id', $chunk)->where('kind', 'magnet')->delete();
                app(MovieRepository::class)->tableQuery('movie_download_searches')->whereIn('movie_id', $chunk)->delete();
                app(MovieRepository::class)->tableQuery('movies')->whereIn('id', $chunk)->delete();
            }
            foreach (array_chunk($performerIds, 500) as $chunk) {
                app(MovieRepository::class)->tableQuery('movie_performers')->whereIn('performer_id', $chunk)->delete();
                app(MovieRepository::class)->tableQuery('performer_aliases')->whereIn('performer_id', $chunk)->delete();
                app(MovieRepository::class)->tableQuery('performer_sources')->whereIn('performer_id', $chunk)->delete();
                app(MovieRepository::class)->tableQuery('performers')->whereIn('id', $chunk)->delete();
            }
            app(MovieRepository::class)->tableQuery('movie_genres')->whereIn('genre_id', $genreIds)->delete();
            app(MovieRepository::class)->tableQuery('genres')->whereIn('id', $genreIds)->delete();
        });
        app(\App\Services\Api\ResponseCacheEpoch::class)->bump();
    }

    private function loadGenres(): void
    {
        $rows = [];
        for ($i = 1; $i <= self::GENRES; $i++) {
            $rows[] = ['label_normalized' => sprintf('sample-%03d', $i), 'label_raw' => sprintf('Sample Genre %03d', $i)];
        }
        $this->saveOwned('genres', 'genre_id', $rows, ['label_normalized']);
        $this->dropStaleOwnedGenres(array_column($rows, 'label_normalized'));
    }

    /**
     * A reload without a wipe must end like a fresh load: genres this dataset created in an older release (a smaller
     * catalog) and their movie links are removed; genres it does not own are never touched.
     *
     * @param list<string> $currentKeys
     */
    private function dropStaleOwnedGenres(array $currentKeys): void
    {
        $repository = app(MovieRepository::class);
        $keep = $repository->tableQuery('genres')->whereIn('label_normalized', $currentKeys)->pluck('id')->all();
        $stale = $repository->tableQuery('sample_dataset_rows')->whereNotNull('genre_id')->whereNotIn('genre_id', $keep)->pluck('genre_id')->all();
        foreach (array_chunk($stale, 500) as $chunk) {
            // Only the links this dataset made are removed; a genre that a real crawl linked meanwhile is kept and simply
            // stops being owned by the dataset.
            $repository->tableQuery('movie_genres')->whereIn('genre_id', $chunk)->where('source_slug', self::SOURCE)->delete();
            $stillLinked = $repository->tableQuery('movie_genres')->whereIn('genre_id', $chunk)->distinct()->pluck('genre_id')->all();
            $repository->tableQuery('genres')->whereIn('id', array_values(array_diff($chunk, $stillLinked)))->delete();
            $repository->tableQuery('sample_dataset_rows')->whereIn('genre_id', $chunk)->delete();
        }
    }

    private function loadPerformers(\DateTimeInterface $now): void
    {
        $rows = [];
        for ($i = 1; $i <= self::PERFORMERS; $i++) {
            $given = self::GIVEN[$i % count(self::GIVEN)];
            $family = self::FAMILY[intdiv($i, 3) % count(self::FAMILY)];
            $rich = $i % 7 !== 2; // every seventh performer has almost no bio: the screens must cope
            $row = [
                'source_slug' => self::SOURCE,
                'external_id' => sprintf('p%04d', $i),
                'uuid' => $this->uuid('performer', $i),
                'name_romaji' => $given . ' ' . $family . ' ' . $i,
                'name_kanji' => $i % 3 === 0 ? self::KANJI[$i % 10] . self::KANJI[($i + 3) % 10] : null,
                'name_kana' => $i % 3 === 0 ? 'サンプル' . $i : null,
                'profile_url' => null,
                'image_url' => null,
                'crawled_at' => $now,
                'birth_date' => $rich ? sprintf('%d-%02d-%02d', 1985 + $i % 18, 1 + $i % 12, 1 + $i % 27) : null,
                'height_cm' => $rich ? 150 + $i % 25 : null,
                'bust' => $rich ? 78 + $i % 14 : null,
                'waist' => $rich ? 54 + $i % 8 : null,
                'hip' => $rich ? 80 + $i % 14 : null,
                'cup' => $rich ? self::CUPS[$i % count(self::CUPS)] : null,
                'blood_type' => $rich ? self::BLOOD[$i % 4] : null,
                'bio_text' => $rich ? 'Generated sample profile number ' . $i . '.' : null,
                'debut_date' => $rich ? sprintf('%d-%02d-01', 2008 + $i % 16, 1 + $i % 12) : null,
                'location' => $rich ? self::CITIES[$i % count(self::CITIES)] : null,
                'attrs' => '{}',
                'needs_review' => false,
                'first_seen_at' => $now,
                'updated_at' => $now,
            ];
            $rows[] = $row;
        }
        $this->saveOwned('performers', 'performer_id', $rows, ['source_slug', 'external_id']);

        $ids = $this->owned('performers', 'performer_id')->pluck('id', 'external_id')->all();
        $aliases = [];
        // One performer with several aliases, a few more with one.
        foreach (['Sample Alias One', 'Sample Alias Two', 'サンプル別名'] as $alias) {
            $aliases[] = ['performer_id' => $ids['p0001'], 'alias' => $alias, 'kind' => 'alias'];
        }
        for ($i = 10; $i <= self::PERFORMERS; $i += 10) {
            $aliases[] = ['performer_id' => $ids[sprintf('p%04d', $i)], 'alias' => 'Sample Nick ' . $i, 'kind' => 'alias'];
        }
        // Every sample performer is known by one source page, like a crawled performer.
        $sources = [];
        foreach ($ids as $external => $performerId) {
            $row = app(MovieRepository::class)->tableQuery('performers')->where('id', $performerId)->first(['name_romaji', 'name_kanji', 'name_kana', 'birth_date']);
            $sources[] = ['performer_id' => $performerId, 'site_slug' => self::SOURCE, 'mongo_id' => 'sample-' . $external, 'external_id' => $external, 'source_url' => null, 'crawled_at' => $now, 'name_romaji' => $row->name_romaji, 'name_kanji' => $row->name_kanji, 'name_kana' => $row->name_kana, 'birth_date' => $row->birth_date];
        }
        foreach (array_chunk($sources, 100) as $chunk) {
            app(MovieRepository::class)->tableQuery('performer_sources')->upsert($chunk, ['site_slug', 'mongo_id'], ['performer_id', 'name_romaji', 'name_kanji', 'name_kana', 'birth_date', 'crawled_at']);
        }

        // The aliases belong to the sample performers: replace them, so a second load adds nothing.
        app(MovieRepository::class)->tableQuery('performer_aliases')->whereIn('performer_id', array_values($ids))->delete();
        app(MovieRepository::class)->tableQuery('performer_aliases')->insert($aliases);
    }

    private function loadMovies(\DateTimeInterface $now): void
    {
        $rows = [];
        for ($i = 1; $i <= self::MOVIES; $i++) {
            $code = sprintf('SMP-%04d', $i);
            $rows[] = [
                'uuid' => $this->uuid('movie', $i),
                'display_code' => $code,
                'code_normalized' => NormalizedCode::from($code)->value(),
                'title_jp' => $i % 4 === 0 ? 'サンプル作品 第' . $i . '話' : null,
                'title_en' => $i % 9 === 0 ? null : 'Sample Movie Number ' . $i,
                'description' => $i % 5 === 0 ? null : 'Generated description of sample movie ' . $i . '.',
                'release_date' => date('Y-m-d', strtotime('2023-01-01 +' . ($i * 2) . ' days')),
                'runtime_minutes' => $i % 11 === 0 ? null : 60 + $i % 120,
                'censored' => $i % 3 === 0 ? Movie::CENSORED_UNCENSORED : Movie::CENSORED_CENSORED,
                'maker' => sprintf('Sample Maker %02d', 1 + $i % self::MAKERS),
                'label' => $i % 6 === 0 ? null : sprintf('Sample Label %d', 1 + $i % 6),
                'series' => $i % 5 === 0 ? sprintf('Sample Series %02d', 1 + intdiv($i, 5) % self::SERIES) : null,
                'community_score' => $i % 8 === 0 ? null : round(2 + ($i % 80) / 10, 2),
                'cover_url' => null,
                'cover_thumb_url' => null,
                'attrs' => '{}',
                'completeness_tier' => $i % 4,
                // One movie that was taken off the sources: it must stay out of lists and calendars.
                'delisted_at' => $i === 1 ? $now : null,
                'needs_review' => $i % 50 === 0,
                'first_seen_at' => $now,
                'updated_at' => $now,
                'crawled_at' => $now,
            ];
        }
        $this->saveOwned('movies', 'movie_id', $rows, ['code_normalized']);

        $movies = $this->owned('movies', 'movie_id')->pluck('id', 'display_code')->all();
        $performers = $this->owned('performers', 'performer_id')->pluck('id', 'external_id')->all();
        $genres = $this->owned('genres', 'genre_id')->pluck('id', 'label_normalized')->all();

        $codes = $links = $movieGenres = $observations = [];
        for ($i = 1; $i <= self::MOVIES; $i++) {
            $code = sprintf('SMP-%04d', $i);
            $id = $movies[$code];
            $normalized = NormalizedCode::from($code)->value();
            // Movies 2..11 are known by two sources.
            $sources = $i >= 2 && $i <= 11 ? [self::SOURCE, 'sample-b'] : [self::SOURCE];
            foreach ($sources as $source) {
                $codes[] = ['movie_id' => $id, 'code' => $code, 'code_normalized' => $normalized, 'kind' => 'dvd', 'source_slug' => $source, 'source_url' => null, 'crawled_at' => $now];
                $observations[] = ['movie_id' => $id, 'field' => 'title_en', 'value' => 'Sample Movie Number ' . $i, 'value_hash' => hash('sha256', 'title_en' . $i . $source), 'source_slug' => $source, 'source_url' => null, 'crawled_at' => $now, 'is_primary' => $source === self::SOURCE];
            }
            foreach ([$i % self::PERFORMERS, ($i * 7) % self::PERFORMERS] as $n) {
                $links[$id . '-' . ($n + 1)] = ['movie_id' => $id, 'performer_id' => $performers[sprintf('p%04d', $n + 1)], 'source_slug' => self::SOURCE, 'crawled_at' => $now];
            }
            foreach ([1 + $i % self::GENRES, 1 + ($i * 3) % self::GENRES] as $g) {
                $movieGenres[$id . '-' . $g] = ['movie_id' => $id, 'genre_id' => $genres[sprintf('sample-%03d', $g)], 'source_slug' => self::SOURCE, 'crawled_at' => $now];
            }
        }
        foreach (array_chunk($codes, 200) as $chunk) {
            app(MovieRepository::class)->tableQuery('movie_codes')->upsert($chunk, ['code_normalized', 'source_slug'], ['movie_id', 'code', 'kind', 'crawled_at']);
        }
        foreach (array_chunk(array_values($links), 200) as $chunk) {
            app(MovieRepository::class)->tableQuery('movie_performers')->insertOrIgnore($chunk);
        }
        foreach (array_chunk(array_values($movieGenres), 200) as $chunk) {
            app(MovieRepository::class)->tableQuery('movie_genres')->insertOrIgnore($chunk);
        }
        // Observations have no natural key: write them once, and only for movies that have none yet.
        $have = app(MovieRepository::class)->tableQuery('movie_observations')->whereIn('movie_id', array_values($movies))->distinct()->pluck('movie_id')->flip()->all();
        $fresh = array_values(array_filter($observations, static fn(array $o): bool => ! isset($have[$o['movie_id']])));
        foreach (array_chunk($fresh, 200) as $chunk) {
            app(MovieRepository::class)->tableQuery('movie_observations')->insert($chunk);
        }

        // Every 10th sample movie ships with a deterministic magnet for downloads QA (SMP-0010, …).
        $magnets = [];
        for ($i = 10; $i <= self::MOVIES; $i += 10) {
            $code = sprintf('SMP-%04d', $i);
            $id = $movies[$code] ?? null;
            if ($id === null) {
                continue;
            }
            $magnets[] = [
                'movie_id' => $id,
                'kind' => 'magnet',
                'url' => 'magnet:?xt=urn:btih:' . str_repeat(sprintf('%02x', $i % 256), 20),
                'meta' => null,
                'source_slug' => self::SOURCE,
                'crawled_at' => $now,
            ];
        }
        foreach (array_chunk($magnets, 100) as $chunk) {
            app(MovieRepository::class)->tableQuery('movie_media')->upsert(
                $chunk,
                ['movie_id', 'kind', 'url'],
                ['source_slug', 'crawled_at'],
            );
        }
    }

    private function index(): int
    {
        if ($this->indexer === null) {
            return 0;
        }

        $indexed = 0;
        app(MovieRepository::class)->modelQuery(Movie::class)->whereIn('id', app(MovieRepository::class)->tableQuery('sample_dataset_rows')->select('movie_id')->whereNotNull('movie_id'))->with(['codes', 'performers', 'genres'])->chunkById(100, function ($movies) use (&$indexed): void {
            foreach ($movies as $movie) {
                $indexed += $this->indexer->indexMovie($movie) ? 1 : 0;
            }
        });
        app(MovieRepository::class)->modelQuery(Performer::class)->whereIn('id', app(MovieRepository::class)->tableQuery('sample_dataset_rows')->select('performer_id')->whereNotNull('performer_id'))->chunkById(100, function ($performers) use (&$indexed): void {
            foreach ($performers as $performer) {
                $indexed += $this->indexer->indexPerformer($performer) ? 1 : 0;
            }
        });

        return $indexed;
    }

    private function owned(string $table, string $column): Builder
    {
        return app(MovieRepository::class)->tableQuery($table)->whereIn('id', app(MovieRepository::class)->tableQuery('sample_dataset_rows')->select($column)->whereNotNull($column));
    }

    /** @param list<array<string, mixed>> $rows @param list<string> $keys */
    private function saveOwned(string $table, string $column, array $rows, array $keys): void
    {
        foreach ($rows as $row) {
            $query = app(MovieRepository::class)->tableQuery($table)->where(array_intersect_key($row, array_flip($keys)));
            $id = $query->value('id');
            if ($id === null) {
                // A concurrent insert must fail its unique constraint, never become an upsert over real data.
                $id = app(MovieRepository::class)->tableQuery($table)->insertGetId($row);
                app(MovieRepository::class)->tableQuery('sample_dataset_rows')->insert([$column => $id]);
                continue;
            }
            if (! app(MovieRepository::class)->tableQuery('sample_dataset_rows')->where($column, $id)->exists()) {
                throw new RuntimeException('Sample dataset collision with an unmarked ' . $table . ' row.');
            }
            $this->owned($table, $column)->where('id', $id)->update(array_diff_key($row, ['uuid' => 1, 'first_seen_at' => 1]));
        }
    }

    private function uuid(string $kind, int $n): string
    {
        $h = hash('sha256', 'javmeta-sample-' . $kind . '-' . $n);

        return sprintf('%s-%s-4%s-a%s-%s', substr($h, 0, 8), substr($h, 8, 4), substr($h, 13, 3), substr($h, 17, 3), substr($h, 20, 12));
    }
}
