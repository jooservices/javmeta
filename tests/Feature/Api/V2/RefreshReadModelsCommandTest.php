<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V2;

use Tests\Support\Api\V2\FakeReadModelDatabase;
use Tests\TestCase;

/** xcrawler:read-models:refresh: reports a refusal cleanly (SQLite, busy lock) and prints the rows of every rebuilt table. */
final class RefreshReadModelsCommandTest extends TestCase
{
    public function test_on_sqlite_it_fails_with_a_message_and_touches_nothing(): void
    {
        $this->artisan('xcrawler:read-models:refresh')->expectsOutput('The read models exist only on PostgreSQL.')->assertFailed();
    }

    public function test_it_fails_when_another_rebuild_holds_the_lock(): void
    {
        $database = FakeReadModelDatabase::install()->driver('pgsql')->answer('pg_try_advisory_lock', [['locked' => false]]);

        $this->artisan('xcrawler:read-models:refresh')->expectsOutput('Another read-model rebuild is running.')->assertFailed();
        self::assertSame([], $database->statements());
    }

    public function test_it_prints_the_row_count_of_every_rebuilt_table_and_releases_the_lock(): void
    {
        $database = FakeReadModelDatabase::install()->driver('pgsql')->lenient()
            ->answer('pg_try_advisory_lock', [['locked' => true]])
            ->answer('FROM rm_performers_new', [['n' => 5]])
            ->answer('FROM rm_movies_new', [['n' => 7]])
            ->answer('FROM rm_perf_attr', [['n' => 9]]);

        $this->artisan('xcrawler:read-models:refresh')->expectsOutput('rm_performers: 5 rows')->expectsOutput('rm_movies: 7 rows')->expectsOutput('rm_perf_attr: 9 rows')->assertSuccessful();

        $queries = $database->queries();
        self::assertStringContainsString('pg_advisory_unlock', end($queries));
        self::assertContains('TRUNCATE rm_perf_attr', $database->statements());
    }

    public function test_a_failing_rebuild_still_releases_the_lock(): void
    {
        $database = FakeReadModelDatabase::install()->driver('pgsql')->lenient()->answer('pg_try_advisory_lock', [['locked' => true]]);
        $database->failStatementsWith('boom');

        $this->artisan('xcrawler:read-models:refresh')->expectsOutput('boom')->assertFailed();
        $queries = $database->queries();
        self::assertStringContainsString('pg_advisory_unlock', end($queries));
    }
}
