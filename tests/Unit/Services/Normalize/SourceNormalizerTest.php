<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Normalize;

use App\Data\Crawl\PerformerDraft;
use App\Services\Normalize\NormalizationFailedException;
use App\Services\Normalize\Normalizers\CatalogSourceNormalizer;
use App\Services\Normalize\Normalizers\DefaultSourceNormalizer;
use App\Services\Normalize\Normalizers\JableSourceNormalizer;
use App\Services\Normalize\SourceNormalizerRegistry;
use Carbon\CarbonImmutable;
use JOOservices\CrawlerX\Dto\Entity\MovieDto;
use JOOservices\CrawlerX\Dto\Entity\PerformerDto;
use JOOservices\CrawlerX\Dto\Entity\ScreenshotDto;
use Tests\TestCase;

final class SourceNormalizerTest extends TestCase
{
    public function test_default_normalizer_maps_typed_fields_and_known_metadata_keys(): void
    {
        $normalizer = $this->registry()->forSlug('onejav');
        self::assertInstanceOf(DefaultSourceNormalizer::class, $normalizer);

        $movie = new MovieDto(
            externalId: 'ymds282',
            title: 'Sample Title',
            code: 'YMDS-282',
            coverUrl: 'https://onejav.com/cover.jpg',
            description: 'A description',
            date: '2024-05-15',
            duration: 120,
            performers: [new PerformerDto(name: 'Aoi Example')],
            tags: ['Drama'],
            screenshots: [new ScreenshotDto('https://onejav.com/shot-1.jpg', 'https://onejav.com/thumb-1.jpg')],
            metadata: ['download_url' => 'https://onejav.com/dl/ymds282'],
        );

        $draft = $normalizer->normalizeMovie(
            $movie,
            $movie->metadata,
            'onejav',
            'https://onejav.com/torrent/ymds282',
            CarbonImmutable::parse('2026-09-18T10:00:00Z'),
        );

        self::assertSame('onejav', $draft->sourceSlug);
        self::assertSame('https://onejav.com/torrent/ymds282', $draft->sourceUrl);
        self::assertSame('YMDS-282', $draft->code);
        self::assertSame('Sample Title', $draft->titleJp);
        self::assertSame('2024-05-15', $draft->releaseDate?->toDateString());
        self::assertSame(120, $draft->runtimeMinutes);
        self::assertSame(['Drama'], $draft->genres);
        self::assertSame('https://onejav.com/cover.jpg', $draft->coverUrl);
        self::assertSame([['url' => 'https://onejav.com/dl/ymds282']], $draft->extras['magnets']);
        self::assertSame([['url' => 'https://onejav.com/shot-1.jpg', 'thumbnail_url' => 'https://onejav.com/thumb-1.jpg']], $draft->extras['gallery']);

        $performer = $draft->performers[0];
        self::assertInstanceOf(PerformerDraft::class, $performer);
        self::assertSame('onejav', $performer->sourceSlug);
        self::assertSame('Aoi Example', $performer->externalId);
        self::assertSame('Aoi Example', $performer->nameRomaji);
    }

    public function test_absent_fields_stay_null_never_empty_strings(): void
    {
        $normalizer = $this->registry()->forSlug('missav');

        $movie = new MovieDto(title: 'Only Title', code: 'SSIS-001');

        $draft = $normalizer->normalizeMovie(
            $movie,
            [],
            'missav',
            'https://missav.ws/en/ssis-001',
            CarbonImmutable::now(),
        );

        self::assertNull($draft->releaseDate);
        self::assertNull($draft->runtimeMinutes);
        self::assertNull($draft->maker);
        self::assertNull($draft->label);
        self::assertNull($draft->series);
        self::assertNull($draft->coverUrl);
        self::assertNull($draft->titleEn);
        self::assertNull($draft->extras['score']);
        self::assertSame([], $draft->genres);
        self::assertSame([], $draft->performers);
        self::assertSame([], $draft->extras['magnets']);
        self::assertSame([], $draft->extras['hls']);
        self::assertSame([], $draft->extras['gallery']);
    }

    public function test_genres_union_deduplicates_by_normalized_label(): void
    {
        $normalizer = $this->registry()->forSlug('javdb');

        $movie = new MovieDto(
            title: 'Dup',
            code: 'SSIS-001',
            tags: ['Drama', 'drama', 'Solowork'],
            metadata: ['Genre' => 'Drama, Romance'],
        );

        $draft = $normalizer->normalizeMovie($movie, $movie->metadata, 'javdb', 'https://javdb.com/v/1', CarbonImmutable::now());

        self::assertSame(['Drama', 'Solowork', 'Romance'], $draft->genres);
    }

    public function test_catalog_normalizer_maps_label_keys_and_score(): void
    {
        $normalizer = $this->registry()->forSlug('javdb');
        self::assertInstanceOf(CatalogSourceNormalizer::class, $normalizer);

        $movie = new MovieDto(
            title: 'Catalog Title',
            code: 'SSIS-001',
            date: '2023-07-01',
            metadata: [
                'Maker' => 'MOODYZ',
                'Label' => 'MOODYZ Collection',
                'Series' => 'Example Series',
                'Rating' => '8.5',
            ],
        );

        $draft = $normalizer->normalizeMovie($movie, $movie->metadata, 'javdb', 'https://javdb.com/v/1', CarbonImmutable::now());

        self::assertSame('MOODYZ', $draft->maker);
        self::assertSame('MOODYZ Collection', $draft->label);
        self::assertSame('Example Series', $draft->series);
        self::assertSame(8.5, $draft->extras['score']);
        self::assertSame('2023-07-01', $draft->releaseDate?->toDateString());
    }

    public function test_per_source_key_map_override_is_applied(): void
    {
        config()->set('javmeta_sources.sources.onejav.normalize.key_map', ['maker' => ['studio']]);

        $normalizer = $this->registry()->forSlug('onejav');

        $movie = new MovieDto(title: 'Studio Title', code: 'YMDS-282', metadata: ['studio' => 'ACME']);

        $draft = $normalizer->normalizeMovie($movie, $movie->metadata, 'onejav', 'https://onejav.com/torrent/ymds282', CarbonImmutable::now());

        self::assertSame('ACME', $draft->maker);
    }

    public function test_it_keeps_only_valid_media_urls_when_metadata_has_mixed_entries(): void
    {
        config()->set('javmeta_sources.sources.onejav.normalize.key_map', [
            'magnets' => ['downloads'],
            'hls' => ['stream'],
        ]);
        $normalizer = new DefaultSourceNormalizer();
        $movie = new MovieDto(
            title: 'Media Title',
            code: 'YMDS-282',
            metadata: [
                'downloads' => [
                    'invalid entry',
                    ['url' => '  '],
                    ['url' => ' magnet:?xt=urn:btih:0123456789abcdef0123456789abcdef01234567 ', 'seeders' => 4],
                ],
                'stream' => [
                    'manifest_url' => ' https://onejav.com/sample.m3u8 ',
                    'video_id' => '42',
                    'expires_at' => '2026-10-01T00:00:00Z',
                ],
            ],
        );

        $draft = $normalizer->normalizeMovie(
            $movie,
            $movie->metadata,
            'onejav',
            'https://onejav.com/torrent/ymds282',
            CarbonImmutable::parse('2026-10-01T00:00:00Z'),
        );

        self::assertSame([
            ['url' => 'magnet:?xt=urn:btih:0123456789abcdef0123456789abcdef01234567', 'seeders' => 4],
        ], $draft->extras['magnets']);
        self::assertSame([
            ['url' => 'https://onejav.com/sample.m3u8', 'video_id' => '42'],
        ], $draft->extras['hls']);
    }

    public function test_it_wraps_a_single_magnet_url_string_from_metadata(): void
    {
        config()->set('javmeta_sources.sources.onejav.normalize.key_map', ['magnets' => ['download_url']]);
        $normalizer = new DefaultSourceNormalizer();
        $movie = new MovieDto(
            title: 'Magnet Title',
            code: 'YMDS-282',
            metadata: ['download_url' => ' magnet:?xt=urn:btih:0123456789abcdef0123456789abcdef01234567 '],
        );

        $draft = $normalizer->normalizeMovie(
            $movie,
            $movie->metadata,
            'onejav',
            'https://onejav.com/torrent/ymds282',
            CarbonImmutable::parse('2026-10-01T00:00:00Z'),
        );

        self::assertSame([
            ['url' => 'magnet:?xt=urn:btih:0123456789abcdef0123456789abcdef01234567'],
        ], $draft->extras['magnets']);
    }

    public function test_it_maps_directors_when_metadata_contains_strings_and_named_records(): void
    {
        config()->set('javmeta_sources.sources.onejav.normalize.key_map', ['directors' => ['director']]);
        $normalizer = new DefaultSourceNormalizer();
        $movie = new MovieDto(
            title: 'Director Title',
            code: 'YMDS-282',
            metadata: ['director' => ['  A. Director ', ['name' => 'B. Director'], ['name' => '  '], 'A. Director']],
        );

        $draft = $normalizer->normalizeMovie(
            $movie,
            $movie->metadata,
            'onejav',
            'https://onejav.com/torrent/ymds282',
            CarbonImmutable::parse('2026-10-01T00:00:00Z'),
        );

        self::assertSame(['A. Director', 'B. Director'], $draft->directors);
    }

    public function test_it_keeps_optional_values_null_when_source_values_are_invalid(): void
    {
        $normalizer = new DefaultSourceNormalizer();
        $movie = new MovieDto(
            title: 'Optional Fields',
            code: 'YMDS-282',
            date: 'not-a-date',
            duration: 0,
            performers: [new PerformerDto(name: '  ')],
        );

        $draft = $normalizer->normalizeMovie(
            $movie,
            [],
            'onejav',
            'https://onejav.com/torrent/ymds282',
            CarbonImmutable::parse('2026-10-01T00:00:00Z'),
        );

        self::assertNull($draft->releaseDate);
        self::assertNull($draft->runtimeMinutes);
        self::assertSame([], $draft->performers);
    }

    public function test_per_source_title_locale_maps_title_to_title_en(): void
    {
        $normalizer = $this->registry()->forSlug('onepondo');

        $movie = new MovieDto(title: 'English Title', code: '060426_001');

        $draft = $normalizer->normalizeMovie($movie, [], 'onepondo', 'https://en.1pondo.tv/movies/060426_001/', CarbonImmutable::now());

        self::assertNull($draft->titleJp);
        self::assertSame('English Title', $draft->titleEn);
        self::assertSame('1PONDO-060426_001', $draft->code);
    }

    public function test_caribbeancom_qualifies_hyphen_date_id(): void
    {
        $normalizer = $this->registry()->forSlug('caribbeancom');

        $movie = new MovieDto(title: 'Caribbean Title', code: '080826-001');

        $draft = $normalizer->normalizeMovie(
            $movie,
            [],
            'caribbeancom',
            'https://en.caribbeancom.com/eng/moviepages/080826-001/index.html',
            CarbonImmutable::now(),
        );

        self::assertSame('CARIBBEANCOM-080826-001', $draft->code);
        self::assertSame('Caribbean Title', $draft->titleJp ?? $draft->titleEn);
    }

    public function test_jable_normalizer_maps_title_en_and_hls_stream_descriptor(): void
    {
        $normalizer = $this->registry()->forSlug('jable');
        self::assertInstanceOf(JableSourceNormalizer::class, $normalizer);

        $movie = new MovieDto(
            title: 'Jable Title',
            code: 'FJIN-091',
            metadata: [
                'stream' => [
                    'manifest_url' => 'https://jable.tv/hls/fjin-091.m3u8',
                    'video_id' => '42',
                    'expires_at' => '2026-09-18T12:00:00+00:00',
                    'poster_url' => 'https://jable.tv/poster.jpg',
                ],
            ],
        );

        $draft = $normalizer->normalizeMovie($movie, $movie->metadata, 'jable', 'https://en.jable.tv/videos/fjin-091/', CarbonImmutable::now());

        self::assertNull($draft->titleJp);
        self::assertSame('Jable Title', $draft->titleEn);
        self::assertSame([
            [
                'url' => 'https://jable.tv/hls/fjin-091.m3u8',
                'video_id' => '42',
                'expires_at' => '2026-09-18T12:00:00+00:00',
                'poster_url' => 'https://jable.tv/poster.jpg',
            ],
        ], $draft->extras['hls']);
    }

    public function test_jable_without_stream_descriptor_fails_normalization(): void
    {
        $normalizer = $this->registry()->forSlug('jable');

        $movie = new MovieDto(title: 'Jable Title', code: 'FJIN-091');

        $this->expectException(NormalizationFailedException::class);

        $normalizer->normalizeMovie($movie, [], 'jable', 'https://en.jable.tv/videos/fjin-091/', CarbonImmutable::now());
    }

    public function test_missing_code_throws_normalization_failed(): void
    {
        $normalizer = $this->registry()->forSlug('missav');

        $movie = new MovieDto(title: 'No Code');

        $this->expectException(NormalizationFailedException::class);

        $normalizer->normalizeMovie($movie, [], 'missav', 'https://missav.ws/en/1', CarbonImmutable::now());
    }

    public function test_missing_title_throws_normalization_failed(): void
    {
        $normalizer = $this->registry()->forSlug('missav');

        $movie = new MovieDto(code: 'SSIS-001');

        $this->expectException(NormalizationFailedException::class);

        $normalizer->normalizeMovie($movie, [], 'missav', 'https://missav.ws/en/1', CarbonImmutable::now());
    }

    public function test_non_normalizable_code_throws_normalization_failed(): void
    {
        $normalizer = $this->registry()->forSlug('missav');

        $movie = new MovieDto(title: 'Bad Code', code: 'nonsense-without-digits');

        $this->expectException(NormalizationFailedException::class);

        $normalizer->normalizeMovie($movie, [], 'missav', 'https://missav.ws/en/1', CarbonImmutable::now());
    }

    public function test_registry_returns_null_for_unknown_slug(): void
    {
        self::assertNull($this->registry()->forSlug('not-a-frozen-source'));
    }

    private function registry(): SourceNormalizerRegistry
    {
        return app(SourceNormalizerRegistry::class);
    }
}
