<?php

declare(strict_types=1);

namespace Tests\Feature\Crawl;

use App\Models\Source;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SyncSourcesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_source_listed_as_disabled_is_stored_disabled(): void
    {
        config()->set('javmeta_sources.disabled', ['off-site']);
        config()->set('javmeta_sources.sources', [
            'off-site' => ['name' => 'Off', 'base_url' => 'https://off.test', 'enabled' => true, 'priority' => 1],
            'on-site' => ['name' => 'On', 'base_url' => 'https://on.test', 'enabled' => true, 'priority' => 2],
        ]);

        $this->artisan('crawler:sync-sources')->assertSuccessful();

        $this->assertFalse((bool) Source::query()->find('off-site')?->enabled);
        $this->assertTrue((bool) Source::query()->find('on-site')?->enabled);
    }

    public function test_avfan_is_disabled_by_default(): void
    {
        $this->assertContains('avfan', config('javmeta_sources.disabled'));
        $this->assertNotContains('avfan_profiles', config('javmeta_sources.disabled'));
    }

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
