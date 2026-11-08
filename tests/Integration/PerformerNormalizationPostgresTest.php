<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\Performer;
use App\Repositories\PerformerRepository;
use Illuminate\Support\Facades\DB;

final class PerformerNormalizationPostgresTest extends IntegrationTestCase
{
    public function test_facets_and_backfill_work_on_a_26000_performer_postgres_catalog(): void
    {
        self::assertSame('pgsql', DB::connection()->getDriverName());
        $this->seedCatalogPerformers();
        $groupTimings = [];
        DB::listen(static function ($query) use (&$groupTimings): void {
            foreach (['cup', 'blood_type', 'location'] as $field) {
                if (str_contains($query->sql, 'LOWER(' . $field . ')')) {
                    $groupTimings[$field] = $query->time;
                }
            }
        });

        $startedAt = hrtime(true);
        $facets = app(PerformerRepository::class)->facets(['include_unknown' => true]);
        $facetTimeMs = (hrtime(true) - $startedAt) / 1_000_000;
        $this->assertCanonicalFacetValues($facets);
        self::assertCount(3, $groupTimings, 'Each indexed performer column should use one grouped query.');
        fwrite(STDOUT, sprintf(
            "\nNormalization facets on 26,000 PostgreSQL performers: total %.2f ms; cup %d ms, blood %d ms, location %d ms.\n",
            $facetTimeMs,
            $groupTimings['cup'],
            $groupTimings['blood_type'],
            $groupTimings['location'],
        ));

        config(['elasticsearch.enabled' => false]);
        $cursor = (int) DB::table('performers')->max('id');
        $performer = Performer::factory()->create([
            'source_slug' => 'normalization-bench',
            'external_id' => 'dirty-performer',
            'blood_type' => 'Ａ型 Rh＋',
            'cup' => 'Q',
            'location' => 'Tokyo',
        ]);
        $this->artisan('performers:backfill-normalization', [
            '--apply' => true,
            '--chunk' => 1,
            '--after' => $cursor,
        ])->assertSuccessful();
        $performer->refresh();
        self::assertSame('A', $performer->blood_type);
        self::assertSame('+', $performer->blood_rh);
        self::assertNull($performer->cup);
        self::assertSame('東京都', $performer->location);
    }

    private function seedCatalogPerformers(): void
    {
        DB::statement(<<<'SQL'
            INSERT INTO performers (uuid, source_slug, external_id, name_romaji, crawled_at, cup, blood_type, location)
            SELECT gen_random_uuid(), 'normalization-bench', n::text, 'Benchmark ' || n, now(),
                CASE WHEN n % 12 = 0 THEN 'FANZA' ELSE chr(65 + (n % 11)) END,
                CASE n % 5 WHEN 0 THEN 'O型' WHEN 1 THEN 'A' WHEN 2 THEN 'Ｂ' WHEN 3 THEN 'AB型' ELSE 'Trans' END,
                CASE n % 3 WHEN 0 THEN 'Tokyo' WHEN 1 THEN '東京都' ELSE '–' END
            FROM generate_series(1, 26000) AS n
            SQL);
        DB::statement('ANALYZE performers');
    }

    /** @param array<string, mixed> $facets */
    private function assertCanonicalFacetValues(array $facets): void
    {
        self::assertSame(26000, $facets['total']);
        $cups = array_column($facets['cups'], 'name');
        sort($cups);
        self::assertSame(range('A', 'K'), $cups);
        $bloodTypes = array_column($facets['blood_types'], 'name');
        sort($bloodTypes);
        self::assertSame(['A', 'AB', 'B', 'O', 'Unknown'], $bloodTypes);
        self::assertSame(['東京都'], array_column($facets['locations'], 'name'));
    }
}
