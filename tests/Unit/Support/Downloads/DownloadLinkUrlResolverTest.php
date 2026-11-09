<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Downloads;

use App\Models\Movie;
use App\Models\MovieSource;
use App\Support\Downloads\DownloadLinkUrlResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class DownloadLinkUrlResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_prefers_known_movie_source_url(): void
    {
        $movie = Movie::factory()->create(['display_code' => 'URL-001']);
        MovieSource::query()->create([
            'movie_id' => $movie->id,
            'site_slug' => 'javdb',
            'mongo_id' => 'abc',
            'source_url' => 'https://javdb.com/v/ABC',
            'source_code' => 'URL-001',
            'crawled_at' => now(),
        ]);

        $url = app(DownloadLinkUrlResolver::class)->resolve((int) $movie->id, (string) $movie->display_code, 'javdb');

        self::assertSame('https://javdb.com/v/ABC', $url);
    }

    public function test_it_builds_deterministic_urls_for_torrent_theme_sources(): void
    {
        $movie = Movie::factory()->create(['display_code' => 'AAA-123']);

        $resolver = app(DownloadLinkUrlResolver::class);

        self::assertSame(
            rtrim((string) config('javmeta_sources.sources.onejav.base_url'), '/') . '/torrent/aaa123',
            $resolver->resolve((int) $movie->id, (string) $movie->display_code, 'onejav'),
        );
        self::assertSame(
            rtrim((string) config('javmeta_sources.sources.javbus.base_url'), '/') . '/AAA-123',
            $resolver->resolve((int) $movie->id, (string) $movie->display_code, 'javbus'),
        );
        self::assertSame(
            rtrim((string) config('javmeta_sources.sources.141jav.base_url'), '/') . '/torrent/aaa123',
            $resolver->resolve((int) $movie->id, (string) $movie->display_code, '141jav'),
        );
        self::assertSame(
            rtrim((string) config('javmeta_sources.sources.ffjav.base_url'), '/') . '/torrent/aaa123',
            $resolver->resolve((int) $movie->id, (string) $movie->display_code, 'ffjav'),
        );
        self::assertNull($resolver->resolve((int) $movie->id, (string) $movie->display_code, 'javdb'));
    }

    public function test_it_returns_null_when_code_or_base_url_is_missing(): void
    {
        $movie = Movie::factory()->create(['display_code' => '']);
        config(['javmeta_sources.sources.onejav.base_url' => '']);

        self::assertNull(app(DownloadLinkUrlResolver::class)->resolve((int) $movie->id, (string) $movie->display_code, 'onejav'));
    }
}
