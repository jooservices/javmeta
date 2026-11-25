<?php

declare(strict_types=1);

namespace Tests\Feature\Sources;

use App\Models\Source;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * The CrawlerX 1.9.0 adapters are registered but start switched off: each is switched on by hand, one at a
 * time, with `crawler:source-toggle` (JOOXCR-720).
 */
final class StagedSourcesTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private const STAGED = [
        'comma', 'digitalark', 'fairway', 'gentosha', 'gravidia', 'hiyoko', 'idolgakuen',
        'muteki', 'naturalhigh', 'silklabo', 'tma', 'tokuma', 'venus', 'yuira',
    ];

    public function test_ac1_the_staged_sources_are_created_switched_off_with_a_listing_seed(): void
    {
        Artisan::call('crawler:sync-sources');

        foreach (self::STAGED as $slug) {
            $source = Source::query()->find($slug);

            self::assertInstanceOf(Source::class, $source, "{$slug} was not created");
            self::assertFalse((bool) $source->enabled, "{$slug} must start switched off");
            self::assertNotSame([], (array) config("javmeta_sources.sources.{$slug}.movie_listing_urls"), "{$slug} has no listing seed");
        }
    }

    public function test_the_sync_never_switches_a_staged_source_on_by_itself(): void
    {
        Artisan::call('crawler:sync-sources');
        Source::query()->whereKey('comma')->update(['enabled' => true]);

        Artisan::call('crawler:sync-sources');

        self::assertTrue((bool) Source::query()->findOrFail('comma')->enabled, 'a source switched on by hand stays on');
    }
}
