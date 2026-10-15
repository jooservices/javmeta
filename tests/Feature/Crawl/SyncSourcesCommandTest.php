<?php

declare(strict_types=1);

namespace Tests\Feature\Crawl;

use App\Models\Source;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SyncSourcesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_sources_creates_configured_sources_and_preserves_runtime_state(): void
    {
        $sourceSlug = fake()->unique()->slug(2);
        $newSourceSlug = fake()->unique()->slug(2);
        $baseUrl = fake()->url();
        $sourceName = fake()->company();
        $newSourceUrl = fake()->url();
        $newSourceName = fake()->company();
        $gapSeconds = fake()->randomFloat(2, 10, 60);

        config()->set('javmeta_sources.sources', [
            $sourceSlug => [
                'name' => $sourceName,
                'base_url' => $baseUrl,
                'enabled' => true,
                'priority' => fake()->numberBetween(1, 100),
                'needs_proxy' => false,
                'gap_seconds_default' => $gapSeconds,
                'gap_seconds_min' => fake()->randomFloat(2, 5, 10),
                'gap_seconds_max' => fake()->randomFloat(2, 60, 120),
            ],
            $newSourceSlug => [
                'name' => $newSourceName,
                'base_url' => $newSourceUrl,
                'enabled' => true,
                'priority' => fake()->numberBetween(1, 100),
            ],
        ]);

        $existingSource = Source::factory()->create([
            'slug' => $sourceSlug,
            'gap_seconds_current' => fake()->randomFloat(2, 10, 60),
            'last_error' => fake()->sentence(),
        ]);

        $this->artisan('crawler:sync-sources')->assertSuccessful();

        $source = $existingSource->fresh();
        $this->assertInstanceOf(Source::class, $source);
        $this->assertSame($sourceName, $source->name);
        $this->assertSame($baseUrl, $source->base_url);
        $this->assertEquals($gapSeconds, $source->gap_seconds_default);
        $this->assertEquals($existingSource->gap_seconds_current, $source->gap_seconds_current);
        $this->assertSame($existingSource->last_error, $source->last_error);
        $this->assertDatabaseHas('sources', [
            'slug' => $newSourceSlug,
            'name' => $newSourceName,
            'base_url' => $newSourceUrl,
            'enabled' => true,
        ]);
    }
}
