<?php

declare(strict_types=1);

namespace App\Services\Dataset;

use App\Models\Movie;
use App\Models\Performer;
use App\Services\Search\ElasticsearchIndexer;
use App\Support\Code\NormalizedCode;
use Illuminate\Support\Facades\DB;

/**
 * A repeatable fake collection for tests and UAT: 520 movies, 210 performers, 30 genres, 20 makers, 10 series, with the
 * links between them and the awkward cases the screens must survive. Everything is generated (no scraped text, no
 * images, no real people) and every row is recognisable (movie codes SMP-nnnn, performer source "sample", genre
 * labels "sample-nn"), so the load is idempotent and the wipe removes exactly what the load created.
 */
final class SampleDataset
{
    public const SOURCE = 'sample';
    public const MOVIES = 520;
    public const PERFORMERS = 210;
    public const GENRES = 30;
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
        DB::transaction(function () use ($now): void {
            $this->loadGenres();
            $this->loadPerformers($now);
            $this->loadMovies($now);
        });

        $indexed = $index ? $this->index() : 0;

        return $this->counts() + ['indexed' => $indexed];
    }

    /** @return array{movies: int, performers: int, genres: int, makers: int, series: int} the rows that the sample owns */
    public function counts(): array
    {
        return [
            'movies' => (int) DB::table('movies')->where('display_code', 'like', 'SMP-%')->count(),
            'performers' => (int) DB::table('performers')->where('source_slug', self::SOURCE)->count(),
            'genres' => (int) DB::table('genres')->where('label_normalized', 'like', 'sample-%')->count(),
            'makers' => (int) DB::table('movies')->where('display_code', 'like', 'SMP-%')->distinct()->count('maker'),
            'series' => (int) DB::table('movies')->where('display_code', 'like', 'SMP-%')->whereNotNull('series')->distinct()->count('series'),
        ];
    }

    /** Remove exactly the rows the load created. */
    public function wipe(): void
    {
        DB::transaction(function (): void {
            $movieIds = DB::table('movies')->where('display_code', 'like', 'SMP-%')->pluck('id')->all();
            $performerIds = DB::table('performers')->where('source_slug', self::SOURCE)->pluck('id')->all();
            $genreIds = DB::table('genres')->where('label_normalized', 'like', 'sample-%')->pluck('id')->all();

            foreach (array_chunk($movieIds, 500) as $chunk) {
                DB::table('movie_genres')->whereIn('movie_id', $chunk)->delete();
                DB::table('movie_performers')->whereIn('movie_id', $chunk)->delete();
                DB::table('movie_observations')->whereIn('movie_id', $chunk)->delete();
                DB::table('movie_codes')->whereIn('movie_id', $chunk)->delete();
                DB::table('movies')->whereIn('id', $chunk)->delete();
            }
            foreach (array_chunk($performerIds, 500) as $chunk) {
                DB::table('movie_performers')->whereIn('performer_id', $chunk)->delete();
                DB::table('performer_aliases')->whereIn('performer_id', $chunk)->delete();
                DB::table('performer_sources')->whereIn('performer_id', $chunk)->delete();
                DB::table('performers')->whereIn('id', $chunk)->delete();
            }
            DB::table('movie_genres')->whereIn('genre_id', $genreIds)->delete();
            DB::table('genres')->whereIn('id', $genreIds)->delete();
        });
    }

    private function loadGenres(): void
    {
        $rows = [];
        for ($i = 1; $i <= self::GENRES; $i++) {
            $rows[] = ['label_normalized' => sprintf('sample-%02d', $i), 'label_raw' => sprintf('Sample Genre %02d', $i)];
        }
        DB::table('genres')->upsert($rows, ['label_normalized'], ['label_raw']);
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
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('performers')->upsert($chunk, ['source_slug', 'external_id'], array_keys(array_diff_key($chunk[0], ['source_slug' => 1, 'external_id' => 1, 'uuid' => 1, 'first_seen_at' => 1])));
        }

        $ids = DB::table('performers')->where('source_slug', self::SOURCE)->pluck('id', 'external_id')->all();
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
            $row = DB::table('performers')->where('id', $performerId)->first(['name_romaji', 'name_kanji', 'name_kana', 'birth_date']);
            $sources[] = ['performer_id' => $performerId, 'site_slug' => self::SOURCE, 'mongo_id' => 'sample-' . $external, 'external_id' => $external, 'source_url' => null, 'crawled_at' => $now, 'name_romaji' => $row->name_romaji, 'name_kanji' => $row->name_kanji, 'name_kana' => $row->name_kana, 'birth_date' => $row->birth_date];
        }
        foreach (array_chunk($sources, 100) as $chunk) {
            DB::table('performer_sources')->upsert($chunk, ['site_slug', 'mongo_id'], ['performer_id', 'name_romaji', 'name_kanji', 'name_kana', 'birth_date', 'crawled_at']);
        }

        // The aliases belong to the sample performers: replace them, so a second load adds nothing.
        DB::table('performer_aliases')->whereIn('performer_id', array_values($ids))->delete();
        DB::table('performer_aliases')->insert($aliases);
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
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('movies')->upsert($chunk, ['code_normalized'], array_keys(array_diff_key($chunk[0], ['code_normalized' => 1, 'uuid' => 1, 'first_seen_at' => 1])));
        }

        $movies = DB::table('movies')->where('display_code', 'like', 'SMP-%')->pluck('id', 'display_code')->all();
        $performers = DB::table('performers')->where('source_slug', self::SOURCE)->pluck('id', 'external_id')->all();
        $genres = DB::table('genres')->where('label_normalized', 'like', 'sample-%')->pluck('id', 'label_normalized')->all();

        $codes = $links = $movieGenres = $observations = [];
        for ($i = 1; $i <= self::MOVIES; $i++) {
            $code = sprintf('SMP-%04d', $i);
            $id = $movies[$code];
            $normalized = NormalizedCode::from($code)->value();
            // Movies 2..11 are known by two sources.
            $sources = $i >= 2 && $i <= 11 ? [self::SOURCE, 'sample-b'] : [self::SOURCE];
            foreach ($sources as $source) {
                $codes[] = ['movie_id' => $id, 'code' => $code, 'code_normalized' => $normalized, 'kind' => 'dvd', 'source_slug' => $source, 'source_url' => null, 'crawled_at' => $now];
                $observations[] = ['movie_id' => $id, 'field' => 'title_en', 'value' => 'Sample Movie Number ' . $i, 'value_hash' => md5('title_en' . $i . $source), 'source_slug' => $source, 'source_url' => null, 'crawled_at' => $now, 'is_primary' => $source === self::SOURCE];
            }
            foreach ([$i % self::PERFORMERS, ($i * 7) % self::PERFORMERS] as $n) {
                $links[$id . '-' . ($n + 1)] = ['movie_id' => $id, 'performer_id' => $performers[sprintf('p%04d', $n + 1)], 'source_slug' => self::SOURCE, 'crawled_at' => $now];
            }
            foreach ([1 + $i % self::GENRES, 1 + ($i * 3) % self::GENRES] as $g) {
                $movieGenres[$id . '-' . $g] = ['movie_id' => $id, 'genre_id' => $genres[sprintf('sample-%02d', $g)], 'source_slug' => self::SOURCE, 'crawled_at' => $now];
            }
        }
        foreach (array_chunk($codes, 200) as $chunk) {
            DB::table('movie_codes')->upsert($chunk, ['code_normalized', 'source_slug'], ['movie_id', 'code', 'kind', 'crawled_at']);
        }
        foreach (array_chunk(array_values($links), 200) as $chunk) {
            DB::table('movie_performers')->insertOrIgnore($chunk);
        }
        foreach (array_chunk(array_values($movieGenres), 200) as $chunk) {
            DB::table('movie_genres')->insertOrIgnore($chunk);
        }
        // Observations have no natural key: write them once, and only for movies that have none yet.
        $have = DB::table('movie_observations')->whereIn('movie_id', array_values($movies))->distinct()->pluck('movie_id')->flip()->all();
        $fresh = array_values(array_filter($observations, static fn(array $o): bool => ! isset($have[$o['movie_id']])));
        foreach (array_chunk($fresh, 200) as $chunk) {
            DB::table('movie_observations')->insert($chunk);
        }
    }

    private function index(): int
    {
        if ($this->indexer === null) {
            return 0;
        }

        $indexed = 0;
        Movie::query()->where('display_code', 'like', 'SMP-%')->with(['codes', 'performers', 'genres'])->chunkById(100, function ($movies) use (&$indexed): void {
            foreach ($movies as $movie) {
                $indexed += $this->indexer->indexMovie($movie) ? 1 : 0;
            }
        });
        Performer::query()->where('source_slug', self::SOURCE)->chunkById(100, function ($performers) use (&$indexed): void {
            foreach ($performers as $performer) {
                $indexed += $this->indexer->indexPerformer($performer) ? 1 : 0;
            }
        });

        return $indexed;
    }

    private function uuid(string $kind, int $n): string
    {
        $h = md5('javmeta-sample-' . $kind . '-' . $n);

        return sprintf('%s-%s-4%s-a%s-%s', substr($h, 0, 8), substr($h, 8, 4), substr($h, 13, 3), substr($h, 17, 3), substr($h, 20, 12));
    }
}
