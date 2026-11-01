<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\Movie;
use App\Models\Performer;
use App\Models\PerformerSource;
use App\Services\Identity\PerformerCoCreditRule;
use App\Services\Identity\PerformerIdentityService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

final class PerformerCoCreditBackfillTest extends IntegrationTestCase
{
    public function test_postgres_dry_run_real_slices_idempotency_and_canonical_search_sources(): void
    {
        self::assertSame('pgsql', DB::connection()->getDriverName());
        $first = $this->pair('Aoi Sakura', 'Sakura Aoi', 'SAMPLE-001');
        $second = $this->pair('Mina Suzuki', 'Suzuki Mina', 'SAMPLE-002');
        $conflict = $this->pair('Ami Tanaka', 'Tanaka Ami', 'SAMPLE-003');
        Performer::query()->whereKey($conflict[0]->id)->update(['height_cm' => 160]);
        Performer::query()->whereKey($conflict[1]->id)->update(['height_cm' => 170]);
        $this->pair('Aika', 'Aika', 'SAMPLE-004');
        $before = $this->counts();
        self::assertSame(['canonical' => 8, 'open_reviews' => 4, 'merges' => 0], $before);
        self::assertSame(0, Artisan::call('performers:backfill-identities', ['--dry-run' => true, '--rule' => 'co_credit']));
        $dryRun = Artisan::output();
        self::assertStringContainsString('co_credit: 2', $dryRun);
        self::assertStringContainsString('reviews_closed: 2', $dryRun);
        self::assertStringContainsString('conflicts_kept: 1', $dryRun);
        self::assertStringContainsString('SAMPLE-001', $dryRun);
        self::assertSame($before, $this->counts());
        self::assertDatabaseCount('performer_identity_event_movie_links', 0);
        self::assertDatabaseCount('performer_identity_event_reviews', 0);
        self::assertSame(0, Artisan::call('performers:backfill-identities', ['--rule' => 'co_credit', '--limit' => 1]));
        self::assertSame(1, $this->counts()['merges']);
        self::assertSame(0, Artisan::call('performers:backfill-identities', ['--rule' => 'co_credit', '--limit' => 1]));
        self::assertSame(['canonical' => 6, 'open_reviews' => 2, 'merges' => 2], $this->counts());
        self::assertSame(0, Artisan::call('performers:backfill-identities', ['--rule' => 'co_credit']));
        self::assertStringContainsString('co_credit: 0', Artisan::output());
        foreach ([$first, $second] as [$root, $child]) {
            $document = $this->esDocument((string) config('elasticsearch.performers_index'), (string) $root->uuid);
            self::assertNotNull($document);
            self::assertCount(2, $document['sources']);
            self::assertEqualsCanonicalizing(['left', 'right'], array_column($document['sources'], 'source_slug'));
            self::assertContains($child->name_romaji, $document['performer_names']);
        }
        self::assertSame(0, Artisan::call('es:setup', ['--reindex' => true]));
        self::assertSame(6, $this->esCount((string) config('elasticsearch.performers_index')));
        fwrite(STDOUT, "\nLocal sample: before canonical=8 reviews=4 merges=0; dry-run unchanged; after canonical=6 reviews=2 merges=2; conflicts kept=1.\n");
        $identity = app(PerformerIdentityService::class);
        $split = $identity->split((int) DB::table('performer_identity_events')->where('event_type', 'merge')->max('id'));
        self::assertSame(7, $this->counts()['canonical']);
        $identity->undoSplit((int) $split->id);
        self::assertSame(6, $this->counts()['canonical']);
    }

    public function test_indexed_co_credit_queries_on_26000_movies_and_performers(): void
    {
        [$left] = $this->pair('Aoi Sakura', 'Sakura Aoi', 'BENCH-001');
        // Synthetic rows are isolated to this test database; no production sample is needed.
        DB::statement("INSERT INTO performers (uuid, source_slug, external_id, name_romaji, crawled_at)
            SELECT gen_random_uuid(), 'benchmark', n::text, n::text || ' Benchmark Performer', now()
            FROM generate_series(1, 25998) n");
        DB::statement("INSERT INTO movies (uuid, display_code, code_normalized, completeness_tier, first_seen_at, updated_at, crawled_at)
            SELECT gen_random_uuid(), 'BENCH-' || n, 'benchmark' || n, 0, now(), now(), now()
            FROM generate_series(2, 26000) n");
        DB::statement("INSERT INTO performer_sources (performer_id, source_performer_id, site_slug, external_id, mongo_id, name_romaji, crawled_at)
            SELECT id, id, source_slug, external_id, 'local:' || id, name_romaji, now() FROM performers");
        DB::statement("INSERT INTO performer_name_keys (source_performer_id, normalized_name)
            SELECT id, CASE WHEN source_slug = 'benchmark' THEN lower(name_romaji) ELSE 'aoi sakura' END FROM performers");
        DB::statement("INSERT INTO movie_performers (movie_id, performer_id, source_slug, crawled_at)
            SELECT id, id, 'benchmark', now() FROM performers WHERE source_slug = 'benchmark'");
        foreach (['performers', 'movies', 'performer_sources', 'performer_name_keys', 'movie_performers'] as $table) {
            DB::statement('ANALYZE ' . $table);
        }
        $source = PerformerSource::query()->where('source_performer_id', $left->id)->firstOrFail();
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            if (str_contains($query->sql, '"own"') || str_contains($query->sql, 'COUNT(DISTINCT')) {
                $queries[] = [$query->sql, $query->bindings, $query->time];
            }
        });
        $evaluation = app(PerformerCoCreditRule::class)->evaluate($source);
        self::assertSame('merge', $evaluation['decision']);
        self::assertSame(26000, Performer::query()->count());
        self::assertSame(26000, Movie::query()->count());
        self::assertNotEmpty($queries);
        foreach ($queries as [$sql, $bindings, $milliseconds]) {
            $plan = DB::select('EXPLAIN (ANALYZE, BUFFERS) ' . $sql, $bindings);
            $text = implode("\n", array_map(static fn(object $row): string => $row->{'QUERY PLAN'}, $plan));
            self::assertStringContainsString('Index', $text);
            self::assertStringNotContainsString('Seq Scan on movies', $text);
            self::assertStringNotContainsString('Seq Scan on performers', $text);
            fwrite(STDOUT, sprintf("\nCo-credit indexed query on 26000 movies/performers: %.3f ms\n", $milliseconds));
        }
    }

    private function pair(string $leftName, string $rightName, string $code): array
    {
        $movie = Movie::factory()->create(['display_code' => $code]);
        $performers = [];
        foreach (['left' => $leftName, 'right' => $rightName] as $source => $name) {
            $performer = Performer::factory()->create(['source_slug' => $source, 'name_romaji' => $name,
                'birth_date' => null, 'blood_type' => null, 'height_cm' => null]);
            $performers[] = $performer;
            DB::table('movie_performers')->insert(['movie_id' => $movie->id, 'performer_id' => $performer->id,
                'source_slug' => $source, 'crawled_at' => now()]);
        }
        DB::table('performer_identity_reviews')->insert(['source_performer_id' => $performers[0]->id,
            'candidate_performer_id' => $performers[1]->id, 'reason' => 'birth_date_missing_or_conflicting',
            'similarity' => 1, 'status' => 'open', 'created_at' => now()]);

        return $performers;
    }

    private function counts(): array
    {
        return ['canonical' => Performer::query()->whereNull('canonical_performer_id')->count(),
            'open_reviews' => DB::table('performer_identity_reviews')->where('status', 'open')->count(),
            'merges' => DB::table('performer_identity_events')->where('event_type', 'merge')->count()];
    }
}
