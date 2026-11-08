<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class RetiredDateMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_retired_date_column_is_added_and_reversible(): void
    {
        self::assertTrue(Schema::hasColumn('performers', 'retired_date'));

        $migration = require base_path('database/migrations/2026_10_17_000001_add_retired_date_to_performers.php');
        $migration->down();
        self::assertFalse(Schema::hasColumn('performers', 'retired_date'));

        $migration->up();
        self::assertTrue(Schema::hasColumn('performers', 'retired_date'));
    }
}
