<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\Movie;
use App\Repositories\ObservabilityServiceRepository;
use Illuminate\Support\Facades\DB;

final class ObservabilityServiceRepositoryTest extends IntegrationTestCase
{
    public function test_postgres_conflict_rate_and_connection_capacity_queries_use_live_postgres(): void
    {
        self::assertSame('pgsql', DB::connection()->getDriverName());

        $movies = Movie::factory()->count(2)->create();
        DB::table('movie_observations')->insert([
            ['movie_id' => $movies[0]->id, 'field' => 'title_jp', 'value' => 'one', 'value_hash' => 'hash-one', 'source_slug' => 'onejav', 'crawled_at' => now(), 'is_primary' => true],
            ['movie_id' => $movies[0]->id, 'field' => 'title_jp', 'value' => 'two', 'value_hash' => 'hash-two', 'source_slug' => 'onejav', 'crawled_at' => now(), 'is_primary' => true],
            ['movie_id' => $movies[1]->id, 'field' => 'title_jp', 'value' => 'one', 'value_hash' => 'hash-one', 'source_slug' => 'onejav', 'crawled_at' => now(), 'is_primary' => true],
        ]);

        $repository = app(ObservabilityServiceRepository::class);
        $rates = $repository->observationConflictRates();
        $titleRate = collect($rates)->firstWhere('field', 'title_jp');

        self::assertNotNull($titleRate);
        self::assertEqualsWithDelta(0.5, (float) $titleRate->conflict_rate, 0.00001);

        $capacity = $repository->postgresConnectionCapacity();
        self::assertNotNull($capacity);
        self::assertGreaterThanOrEqual(0, (int) $capacity->used);
        self::assertGreaterThan(0, (int) $capacity->maximum);
        self::assertLessThanOrEqual((int) $capacity->maximum, (int) $capacity->used);
    }
}
