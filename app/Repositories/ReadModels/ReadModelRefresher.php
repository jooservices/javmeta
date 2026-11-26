<?php

declare(strict_types=1);

namespace App\Repositories\ReadModels;

use App\Services\Normalize\PerformerAttributeNormalizer;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Rebuilds rm_performers, rm_movies and rm_perf_attr from the normalised tables. Each table is built beside the live one and
 * swapped by name in one transaction, so a reader never sees an empty table and a failed rebuild keeps the previous data.
 */
final class ReadModelRefresher
{
    private const LOCK_KEY = 726_001;

    /** Performer attributes the catalogue groups by their canonical value (the same normalizer v1 uses for its facets). */
    private const CANONICAL_FIELDS = ['cup', 'blood_type', 'location'];

    public function __construct(private readonly PerformerAttributeNormalizer $normalizer = new PerformerAttributeNormalizer()) {}

    /** @return array<string, int> rows per table */
    public function refresh(): array
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException('The read models exist only on PostgreSQL.');
        }

        if (! (bool) DB::selectOne('SELECT pg_try_advisory_lock(?) AS locked', [self::LOCK_KEY])->locked) {
            throw new RuntimeException('Another read-model rebuild is running.');
        }

        try {
            $rows = ['rm_performers' => $this->rebuild('rm_performers', ReadModelQueries::PERFORMERS, $this->canonicalize(...)), 'rm_movies' => $this->rebuild('rm_movies', ReadModelQueries::MOVIES)];
            $rows['rm_perf_attr'] = $this->rebuildPairs();
            $this->fillSearchText();

            return $rows;
        } finally {
            DB::select('SELECT pg_advisory_unlock(?)', [self::LOCK_KEY]);
        }
    }

    /** @param (callable(string): void)|null $prepare runs on the filled new table, before it replaces the live one */
    private function rebuild(string $table, string $select, ?callable $prepare = null): int
    {
        $started = microtime(true);
        $new = "{$table}_new";
        DB::unprepared("DROP TABLE IF EXISTS {$new}");
        DB::unprepared("CREATE TABLE {$new} (LIKE {$table} INCLUDING ALL)");
        DB::unprepared("INSERT INTO {$new} {$select}");
        if ($prepare !== null) {
            $prepare($new);
        }
        $count = (int) DB::selectOne("SELECT count(*) AS n FROM {$new}")->n;

        DB::transaction(function () use ($table, $new): void {
            DB::unprepared("DROP TABLE {$table}");
            DB::unprepared("ALTER TABLE {$new} RENAME TO {$table}");

            foreach (DB::select('SELECT indexname FROM pg_indexes WHERE tablename = ? AND indexname LIKE ?', [$table, "{$new}%"]) as $index) {
                DB::unprepared('ALTER INDEX "' . $index->indexname . '" RENAME TO "' . str_replace($new, $table, $index->indexname) . '"');
            }
        });

        $this->record($table, $count, $started);

        return $count;
    }

    /**
     * Cups, blood types and locations come from many sources in many spellings ("osaka", "Osaka", "大阪府"). They are stored
     * once in their canonical form, so every facet, filter and catalog groups them exactly as v1 does. A value the
     * normalizer does not know stays as it is.
     */
    private function canonicalize(string $table): void
    {
        foreach (self::CANONICAL_FIELDS as $field) {
            foreach (DB::select("SELECT DISTINCT {$field} AS raw FROM {$table} WHERE {$field} IS NOT NULL AND {$field} <> ''") as $row) {
                $canonical = $this->normalizer->facetValue($field, mb_strtolower((string) $row->raw));

                if ($canonical !== null && $canonical !== $row->raw) {
                    DB::update("UPDATE {$table} SET {$field} = ? WHERE {$field} = ?", [$canonical, $row->raw]);
                }
            }
        }
    }

    private function rebuildPairs(): int
    {
        $started = microtime(true);

        try {
            DB::transaction(static function (): void {
                DB::unprepared('TRUNCATE rm_perf_attr');

                foreach (ReadModelQueries::PERFORMER_PAIRS as $statement) {
                    DB::unprepared($statement);
                }
            });
        } catch (Throwable $exception) {
            throw new RuntimeException('Rebuilding rm_perf_attr failed: ' . $exception->getMessage(), 0, $exception);
        }

        $count = (int) DB::selectOne('SELECT count(*) AS n FROM rm_perf_attr')->n;
        $this->record('rm_perf_attr', $count, $started);

        return $count;
    }

    /** Movies carry the names of their cast in the search text, so "Hatano Yui" finds her movies. */
    private function fillSearchText(): void
    {
        DB::unprepared('UPDATE rm_movies m SET performer_names = x.names FROM (SELECT mv.id, string_agg(p.name_romaji, \' \' ORDER BY p.movie_count DESC) AS names FROM rm_movies mv, unnest(mv.performer_ids) pid JOIN rm_performers p ON p.id = pid GROUP BY mv.id) x WHERE x.id = m.id');
        DB::unprepared("UPDATE rm_movies SET search_text = lower(concat_ws(' ', code, title_jp, title_en, maker, series, performer_names)) WHERE performer_names IS NOT NULL");
    }

    private function record(string $table, int $rows, float $started): void
    {
        DB::statement(
            'INSERT INTO rm_refresh(name, refreshed_at, row_count, duration_ms) VALUES (?, now(), ?, ?) ON CONFLICT (name) DO UPDATE SET refreshed_at = now(), row_count = EXCLUDED.row_count, duration_ms = EXCLUDED.duration_ms',
            [$table, $rows, (int) ((microtime(true) - $started) * 1000)],
        );
    }
}
