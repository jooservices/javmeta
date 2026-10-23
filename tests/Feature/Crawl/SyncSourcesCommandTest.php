<?php

declare(strict_types=1);

namespace Tests\Feature\Crawl;

use App\Models\Source;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SyncSourcesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_source_starts_on_unless_it_requires_a_login(): void
    {
        config()->set('javmeta_sources.sources', [
            'login-site' => ['name' => 'Login', 'base_url' => 'https://login.test', 'requires_login' => true, 'priority' => 1],
            'on-site' => ['name' => 'On', 'base_url' => 'https://on.test', 'priority' => 2],
        ]);

        $this->artisan('crawler:sync-sources')->assertSuccessful();

        $this->assertFalse((bool) Source::query()->find('login-site')?->enabled);
        $this->assertTrue((bool) Source::query()->find('on-site')?->enabled);
    }

    public function test_sync_never_changes_the_switch_of_an_existing_source(): void
    {
        config()->set('javmeta_sources.sources', [
            'switched-off' => ['name' => 'Off', 'base_url' => 'https://off.test', 'enabled' => true, 'priority' => 1],
            'switched-on' => ['name' => 'On', 'base_url' => 'https://on.test', 'requires_login' => true, 'priority' => 2],
        ]);
        Source::factory()->create(['slug' => 'switched-off', 'enabled' => false]);
        Source::factory()->create(['slug' => 'switched-on', 'enabled' => true]);

        $this->artisan('crawler:sync-sources')->assertSuccessful();
        $this->artisan('crawler:sync-sources')->assertSuccessful();

        $this->assertFalse((bool) Source::query()->find('switched-off')?->enabled);
        $this->assertTrue((bool) Source::query()->find('switched-on')?->enabled);
    }

    public function test_avfan_needs_a_login_and_avfan_profiles_does_not(): void
    {
        $this->assertTrue((bool) config('javmeta_sources.sources.avfan.requires_login'));
        $this->assertFalse((bool) config('javmeta_sources.sources.avfan_profiles.requires_login', false));
        $this->assertNull(config('javmeta_sources.disabled'));
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
