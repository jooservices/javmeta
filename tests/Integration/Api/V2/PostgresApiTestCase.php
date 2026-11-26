<?php

declare(strict_types=1);

namespace Tests\Integration\Api\V2;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;

/** Base of the v2 API integration tests: real PostgreSQL (migrated per test), no Mongo or Elasticsearch needed. */
abstract class PostgresApiTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        self::assertSame('pgsql', DB::getDriverName());
    }
}
