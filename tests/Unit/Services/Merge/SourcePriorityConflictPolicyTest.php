<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Merge;

use App\Models\MovieObservation;
use App\Services\Merge\SourcePriorityConflictPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SourcePriorityConflictPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_highest_authority_source_wins_regardless_of_freshness(): void
    {
        config()->set('javmeta_sources.sources.javdb.priority', 10);
        config()->set('javmeta_sources.sources.onejav.priority', 60);

        $javdb = MovieObservation::factory()->create(['source_slug' => 'javdb', 'value' => 'From JavDB', 'crawled_at' => now()->subDay()]);
        $onejav = MovieObservation::factory()->create(['source_slug' => 'onejav', 'value' => 'From OneJav', 'crawled_at' => now()]);

        $picked = (new SourcePriorityConflictPolicy())->pick('title_jp', collect([$javdb, $onejav]));

        $this->assertSame($javdb->id, $picked?->id);
    }

    public function test_priority_tie_breaks_to_most_recent_crawled_at(): void
    {
        config()->set('javmeta_sources.sources.javdb.priority', 10);

        $older = MovieObservation::factory()->create(['source_slug' => 'javdb', 'value' => 'older', 'crawled_at' => now()->subDays(2)]);
        $newer = MovieObservation::factory()->create(['source_slug' => 'javdb', 'value' => 'newer', 'crawled_at' => now()]);

        $picked = (new SourcePriorityConflictPolicy())->pick('title_jp', collect([$older, $newer]));

        $this->assertSame($newer->id, $picked?->id);
    }

    public function test_null_valued_observation_can_win_the_pick(): void
    {
        config()->set('javmeta_sources.sources.javdb.priority', 10);

        $nonNull = MovieObservation::factory()->create(['source_slug' => 'javdb', 'value' => 'A title', 'crawled_at' => now()->subDay()]);
        $null = MovieObservation::factory()->create(['source_slug' => 'javdb', 'value' => null, 'crawled_at' => now()]);

        $picked = (new SourcePriorityConflictPolicy())->pick('title_jp', collect([$nonNull, $null]));

        $this->assertSame($null->id, $picked?->id);
    }

    public function test_a_source_reporting_nothing_does_not_hide_a_value_from_another_source(): void
    {
        config()->set('javmeta_sources.sources.javdb.priority', 10);
        config()->set('javmeta_sources.sources.onejav.priority', 60);

        $javdbNull = MovieObservation::factory()->create(['source_slug' => 'javdb', 'value' => null, 'crawled_at' => now()]);
        $onejav = MovieObservation::factory()->create(['source_slug' => 'onejav', 'value' => 'From OneJav', 'crawled_at' => now()->subDay()]);

        $picked = (new SourcePriorityConflictPolicy())->pick('maker', collect([$javdbNull, $onejav]));

        $this->assertSame($onejav->id, $picked?->id);
    }

    public function test_source_missing_from_config_is_least_authoritative(): void
    {
        config()->set('javmeta_sources.sources.javdb.priority', 10);

        $mystery = MovieObservation::factory()->create(['source_slug' => 'mystery', 'value' => 'From Mystery', 'crawled_at' => now()]);
        $javdb = MovieObservation::factory()->create(['source_slug' => 'javdb', 'value' => 'From JavDB', 'crawled_at' => now()->subDay()]);

        $picked = (new SourcePriorityConflictPolicy())->pick('title_jp', collect([$mystery, $javdb]));

        $this->assertSame($javdb->id, $picked?->id);
    }

    public function test_empty_observations_pick_nothing(): void
    {
        $this->assertNull((new SourcePriorityConflictPolicy())->pick('title_jp', collect()));
    }
}
