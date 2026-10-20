<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Models\ApiKey;
use App\Models\CrawlQueue;
use App\Models\Genre;
use App\Models\Performer;
use App\Models\Source;
use App\Models\Movie;
use App\Models\MovieCode;
use App\Models\MovieGenre;
use App\Models\MovieMedia;
use App\Models\MovieObservation;
use App\Models\MoviePerformer;
use App\Models\PerformerMedia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class SchemaTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, list<string>> */
    private const REQUIRED_COLUMNS = [
        'movies' => ['id', 'display_code', 'code_normalized', 'title_jp', 'title_en', 'release_date', 'runtime_minutes', 'censored', 'maker', 'label', 'series', 'community_score', 'completeness_tier', 'recrawl_attempts', 'recrawl_status', 'search_vector', 'delisted_at', 'needs_review', 'first_seen_at', 'updated_at', 'crawled_at'],
        'movie_codes' => ['id', 'movie_id', 'code', 'code_normalized', 'kind', 'source_slug', 'source_url', 'crawled_at'],
        'movie_observations' => ['id', 'movie_id', 'field', 'value', 'value_hash', 'source_slug', 'source_url', 'crawled_at', 'is_primary'],
        'genres' => ['id', 'label_normalized', 'label_raw'],
        'movie_genres' => ['movie_id', 'genre_id', 'source_slug', 'crawled_at'],
        'performers' => ['id', 'canonical_performer_id', 'source_slug', 'external_id', 'name_romaji', 'name_kanji', 'name_kana', 'profile_url', 'image_url', 'crawled_at'],
        'performer_aliases' => ['id', 'performer_id', 'source_performer_id', 'source_slug', 'alias', 'kind'],
        'performer_sources' => ['id', 'performer_id', 'source_performer_id', 'site_slug', 'mongo_id', 'external_id', 'source_url', 'name_romaji', 'name_kanji', 'name_kana', 'birth_date', 'cross_link_source', 'cross_link_id', 'removed_at', 'crawled_at'],
        'performer_identity_events' => ['id', 'event_type', 'from_performer_id', 'to_performer_id', 'related_event_id', 'details', 'created_at', 'reversed_at'],
        'performer_identity_reviews' => ['id', 'source_performer_id', 'candidate_performer_id', 'reason', 'similarity', 'status', 'created_at', 'resolved_at'],
        'movie_performers' => ['movie_id', 'performer_id', 'source_slug', 'crawled_at'],
        'movie_media' => ['id', 'movie_id', 'kind', 'url', 'meta', 'source_slug', 'crawled_at'],
        'performer_media' => ['id', 'performer_id', 'source_performer_id', 'kind', 'url', 'meta', 'source_slug', 'crawled_at'],
        'sources' => ['slug', 'name', 'base_url', 'enabled', 'priority', 'needs_proxy', 'gap_seconds_default', 'gap_seconds_min', 'gap_seconds_max', 'gap_seconds_current', 'consecutive_failures', 'circuit_state', 'circuit_opened_at', 'last_success_at', 'last_error_at', 'last_error', 'soft404_markers'],
        'crawl_queue' => ['id', 'source_slug', 'url', 'kind', 'status', 'attempts', 'max_attempts', 'next_attempt_at', 'claimed_at', 'started_at', 'locked_by', 'last_error', 'error_code', 'last_failed_at', 'is_recrawl'],
        'crawl_runs' => ['id', 'source_slug', 'started_at', 'finished_at', 'status', 'pages_fetched', 'movies_new', 'movies_updated', 'failures', 'proxy_requests', 'proxy_bytes', 'browser_fetches'],
        'crawl_events' => ['id', 'source_slug', 'kind', 'url', 'detail', 'created_at'],
        'review_flags' => ['id', 'movie_id', 'code_normalized', 'source_slug', 'consecutive_failures', 'reason', 'flagged_at', 'resolved_at'],
        'api_keys' => ['id', 'prefix', 'key_hash', 'label', 'status', 'revoked_at', 'abuse_rpm', 'created_at'],
        'api_usage_log' => ['id', 'api_key_id', 'endpoint', 'method', 'status_code', 'response_ms', 'ip_hash', 'created_at'],
    ];

    public function test_adr_tables_exist_with_required_columns(): void
    {
        foreach (self::REQUIRED_COLUMNS as $table => $columns) {
            $this->assertTrue(Schema::hasTable($table), "Missing table [{$table}].");

            foreach ($columns as $column) {
                $this->assertTrue(Schema::hasColumn($table, $column), "Missing column [{$table}.{$column}].");
            }
        }
    }

    public function test_movie_media_has_url_only_media_references_without_binary_columns(): void
    {
        $columns = $this->columnTypes('movie_media');

        $this->assertArrayHasKey('url', $columns);
        $this->assertArrayNotHasKey('blob', $columns);
        $this->assertArrayNotHasKey('bytes', $columns);
        $this->assertArrayNotHasKey('binary', $columns);
        $this->assertArrayNotHasKey('content', $columns);

        foreach ($columns as $type) {
            $this->assertNotContains(strtolower($type), ['bytea', 'blob', 'binary', 'varbinary']);
        }
    }

    public function test_required_unique_constraints_exist(): void
    {
        $this->assertUniqueColumns('movie_codes', ['code_normalized', 'source_slug']);
        $this->assertUniqueColumns('performers', ['source_slug', 'external_id']);
        $this->assertUniqueColumns('performer_aliases', ['performer_id', 'alias', 'source_performer_id']);
        $this->assertUniqueColumns('performer_media', ['performer_id', 'kind', 'url', 'source_performer_id']);
        $this->assertUniqueColumns('performer_sources', ['site_slug', 'mongo_id']);
        $this->assertUniqueColumns('performer_identity_reviews', ['source_performer_id', 'candidate_performer_id', 'status']);
        $this->assertUniqueColumns('api_keys', ['key_hash']);
        $this->assertUniqueColumns('genres', ['label_normalized']);
    }

    public function test_seedable_model_factories_create_records(): void
    {
        $movie = Movie::factory()->create();
        $genre = Genre::factory()->create();
        $performer = Performer::factory()->create();

        MovieCode::factory()->for($movie)->create();
        MovieObservation::factory()->for($movie)->create();
        MovieMedia::factory()->for($movie)->create();
        PerformerMedia::factory()->for($performer)->create();
        MovieGenre::factory()->create(['movie_id' => $movie->id, 'genre_id' => $genre->id]);
        MoviePerformer::factory()->create(['movie_id' => $movie->id, 'performer_id' => $performer->id]);
        Source::factory()->create();
        CrawlQueue::factory()->create();
        ApiKey::factory()->create();

        $this->assertDatabaseCount('movies', 1);
        $this->assertDatabaseCount('movie_codes', 1);
        $this->assertDatabaseCount('movie_observations', 1);
        $this->assertDatabaseCount('genres', 1);
        $this->assertDatabaseCount('performers', 1);
        $this->assertDatabaseCount('movie_media', 1);
        $this->assertDatabaseCount('performer_media', 1);
        $this->assertDatabaseCount('movie_genres', 1);
        $this->assertDatabaseCount('movie_performers', 1);
        $this->assertDatabaseCount('sources', 1);
        $this->assertDatabaseCount('crawl_queue', 1);
        $this->assertDatabaseCount('api_keys', 1);
    }

    /** @param list<string> $expected */
    private function assertUniqueColumns(string $table, array $expected): void
    {
        $uniqueColumnSets = $this->uniqueColumnSets($table);

        $this->assertContains($expected, $uniqueColumnSets, "Missing unique constraint on [{$table}] for columns [" . implode(', ', $expected) . '].');
    }

    /** @return array<string, string> */
    private function columnTypes(string $table): array
    {
        if (DB::getDriverName() === 'pgsql') {
            $rows = DB::select('select column_name, data_type from information_schema.columns where table_schema = current_schema() and table_name = ? order by ordinal_position', [$table]);

            return collect($rows)->mapWithKeys(fn(object $row): array => [(string) $row->column_name => (string) $row->data_type])->all();
        }

        $rows = DB::select('PRAGMA table_info(' . $table . ')');

        return collect($rows)->mapWithKeys(fn(object $row): array => [(string) $row->name => (string) $row->type])->all();
    }

    /** @return list<list<string>> */
    private function uniqueColumnSets(string $table): array
    {
        if (DB::getDriverName() === 'pgsql') {
            $rows = DB::select("select constraint_name, column_name from information_schema.table_constraints tc join information_schema.key_column_usage kcu using (constraint_catalog, constraint_schema, constraint_name, table_name) where tc.table_schema = current_schema() and tc.table_name = ? and tc.constraint_type = 'UNIQUE' order by constraint_name, ordinal_position", [$table]);

            return collect($rows)
                ->groupBy('constraint_name')
                ->map(fn($items): array => $items->pluck('column_name')->map(fn(mixed $column): string => (string) $column)->values()->all())
                ->values()
                ->all();
        }

        return collect(DB::select('PRAGMA index_list(' . $table . ')'))
            ->filter(fn(object $row): bool => (int) $row->unique === 1)
            ->map(function (object $row): array {
                return collect(DB::select('PRAGMA index_info(' . $row->name . ')'))
                    ->pluck('name')
                    ->map(fn(mixed $column): string => (string) $column)
                    ->values()
                    ->all();
            })
            ->values()
            ->all();
    }
}
