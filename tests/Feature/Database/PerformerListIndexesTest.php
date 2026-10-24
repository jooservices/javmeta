<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Models\Performer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class PerformerListIndexesTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_indexes_are_idempotent_reversible_and_preserve_name_order(): void
    {
        Performer::factory()->create(['name_romaji' => null, 'name_kanji' => 'B']);
        $performer = Performer::factory()->create(['name_romaji' => 'A']);
        $connection = DB::connection();
        $connection->table('performer_sources')->insert(['performer_id' => $performer->id, 'site_slug' => 'test', 'mongo_id' => fake()->uuid(), 'crawled_at' => now()]);
        $sources = $connection->select('SELECT * FROM performer_sources WHERE performer_id = ?', [$performer->id]);
        $query = "SELECT id FROM performers WHERE canonical_performer_id IS NULL ORDER BY COALESCE(name_romaji, name_kanji, name_kana, ''), id";
        $before = $connection->select($query);
        // SQLite supports these B-tree definitions too: exercise their result semantics without touching staging.
        DB::partialMock()->shouldReceive('getDriverName')->andReturn('pgsql');
        DB::shouldReceive('statement')->andReturnUsing(static fn(string $sql): bool => $connection->statement($sql));
        $migration = require database_path('migrations/2026_10_25_000001_add_performer_list_indexes.php');
        $migration->up();
        $migration->up();
        $this->assertEquals($before, $connection->select($query));
        $this->assertEquals($sources, $connection->select('SELECT * FROM performer_sources WHERE performer_id = ?', [$performer->id]));
        $indexes = $connection->select("SELECT name FROM sqlite_master WHERE type = 'index' AND name IN ('performers_display_name_list_idx', 'performer_sources_performer_list_idx')");
        $this->assertCount(2, $indexes);
        $migration->down();
        $migration->down();
        $this->assertEquals($before, $connection->select($query));
        $this->assertEquals($sources, $connection->select('SELECT * FROM performer_sources WHERE performer_id = ?', [$performer->id]));
    }
}
