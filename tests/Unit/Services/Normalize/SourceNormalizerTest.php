<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Normalize;

use App\Data\Crawl\PerformerDraft;
use App\Services\Normalize\NormalizationFailedException;
use App\Services\Normalize\Normalizers\DefaultSourceNormalizer;
use App\Services\Normalize\Normalizers\JavbtcSourceNormalizer;
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
        $normalizer = $this->registry()->forSlug('onejav');

        $movie = new MovieDto(title: 'Only Title', code: 'SSIS-001');

        $draft = $normalizer->normalizeMovie(
            $movie,
            [],
            'onejav',
            'https://onejav.com/torrent/ssis001',
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

    public function test_legacy_catalog_labels_are_still_understood(): void
    {
        $normalizer = $this->registry()->forSlug('javdb');
        self::assertInstanceOf(DefaultSourceNormalizer::class, $normalizer);

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
    }

    public function test_crawlerx_standard_keys_fill_maker_label_series_score_and_magnets(): void
    {
        foreach (['javdb', 'duga', 'javlibrary', 'fc2'] as $slug) {
            $normalizer = $this->registry()->forSlug($slug);
            $movie = new MovieDto(
                title: 'Standard Title',
                code: 'SSIS-001',
                metadata: [
                    'maker' => 'S1',
                    'label' => 'S1 NO.1 STYLE',
                    'series' => 'Series One',
                    'director' => 'Director A',
                    'rating' => ['score' => 4.5, 'scale' => 5.0, 'votes' => 20],
                    'magnets' => [
                        ['name' => 'a', 'url' => 'magnet:?xt=urn:btih:aaa', 'size_label' => '1GB'],
                        ['name' => 'dup', 'url' => 'magnet:?xt=urn:btih:aaa'],
                    ],
                ],
            );

            $draft = $normalizer->normalizeMovie($movie, $movie->metadata, $slug, 'https://example.test/' . $slug, CarbonImmutable::now());

            self::assertSame('S1', $draft->maker, $slug);
            self::assertSame('S1 NO.1 STYLE', $draft->label, $slug);
            self::assertSame('Series One', $draft->series, $slug);
            self::assertSame(9.0, $draft->extras['score'], $slug);
            self::assertSame(['Director A'], $draft->directors, $slug);
            self::assertCount(1, $draft->extras['magnets'], $slug);
            self::assertSame('1GB', $draft->extras['magnets'][0]['size_label'], $slug);
        }
    }

    public function test_titles_in_both_languages_and_the_original_title_fill_both_columns(): void
    {
        config()->set('javmeta_sources.sources.onepondo.normalize.title_locale', 'en');
        $normalizer = $this->registry()->forSlug('onepondo');

        $both = new MovieDto(title: 'English Title', code: '060426_001', metadata: ['titles' => ['ja' => '日本語タイトル', 'en' => 'English Title']]);
        $draft = $normalizer->normalizeMovie($both, $both->metadata, 'onepondo', 'https://en.1pondo.tv/movies/060426_001/', CarbonImmutable::now());
        self::assertSame('日本語タイトル', $draft->titleJp);
        self::assertSame('English Title', $draft->titleEn);

        $original = new MovieDto(title: 'English Title', code: 'FNS-247', metadata: ['original_title' => 'オリジナル']);
        $draft = $normalizer->normalizeMovie($original, $original->metadata, 'onepondo', 'https://missav.ws/en/fns-247', CarbonImmutable::now());
        self::assertSame('オリジナル', $draft->titleJp);
        self::assertSame('English Title', $draft->titleEn);
    }

    public function test_the_title_language_is_detected_when_the_source_does_not_force_one(): void
    {
        config()->set('javmeta_sources.sources.xcity.normalize.title_locale', 'auto');
        $normalizer = $this->registry()->forSlug('xcity');
        $japanese = new MovieDto(title: '隣の若妻は昼間は天使', code: 'HEZ-888');
        $english = new MovieDto(title: 'Young wife next door', code: 'HEZ-888');

        $jp = $normalizer->normalizeMovie($japanese, [], 'xcity', 'https://xcity.jp/a', CarbonImmutable::now());
        $en = $normalizer->normalizeMovie($english, [], 'xcity', 'https://xcity.jp/b', CarbonImmutable::now());

        self::assertSame('隣の若妻は昼間は天使', $jp->titleJp);
        self::assertNull($jp->titleEn);
        self::assertSame('Young wife next door', $en->titleEn);
        self::assertNull($en->titleJp);
    }

    public function test_an_out_of_range_score_is_dropped(): void
    {
        $normalizer = $this->registry()->forSlug('javdb');
        $movie = new MovieDto(title: 'T', code: 'SSIS-001', metadata: ['rating' => ['score' => 500, 'scale' => 5]]);

        $draft = $normalizer->normalizeMovie($movie, $movie->metadata, 'javdb', 'https://javdb.com/v/1', CarbonImmutable::now());

        self::assertNull($draft->extras['score']);

        $above = new MovieDto(title: 'T', code: 'SSIS-001', metadata: ['rating' => ['score' => 8, 'scale' => 5]]);
        $draft = $normalizer->normalizeMovie($above, $above->metadata, 'javdb', 'https://javdb.com/v/1', CarbonImmutable::now());
        self::assertNull($draft->extras['score']);
    }

    public function test_the_next_score_alias_is_tried_after_an_invalid_rating(): void
    {
        $normalizer = $this->registry()->forSlug('javdb');
        $movie = new MovieDto(title: 'T', code: 'SSIS-001', metadata: ['rating' => ['score' => 500, 'scale' => 5], 'score' => 8]);

        $draft = $normalizer->normalizeMovie($movie, $movie->metadata, 'javdb', 'https://javdb.com/v/1', CarbonImmutable::now());

        self::assertSame(8.0, $draft->extras['score']);
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

    public function test_javbtc_product_code_from_page_fields_is_normalized(): void
    {
        $normalizer = $this->registry()->forSlug('javbtc');
        self::assertInstanceOf(JavbtcSourceNormalizer::class, $normalizer);

        $movie = new MovieDto(
            externalId: 's1no1style-yua-mikami-sougouwiki-celebrity',
            title: 'Big Tits Bursting Out Of Her School Swimsuit',
            code: 'SSNI-00916',
            metadata: ['product_code_raw' => 'ssni00916'],
        );

        $draft = $normalizer->normalizeMovie(
            $movie,
            $movie->metadata,
            'javbtc',
            'https://javbtc.com/r18/s1no1style-yua-mikami-sougouwiki-celebrity',
            CarbonImmutable::now(),
        );

        self::assertSame('SSNI-00916', $draft->code);
    }

    public function test_javbtc_product_code_in_title_is_normalized(): void
    {
        $normalizer = $this->registry()->forSlug('javbtc');
        $movie = new MovieDto(title: 'SSNI-00916 Big Tits');

        $draft = $normalizer->normalizeMovie(
            $movie,
            [],
            'javbtc',
            'https://javbtc.com/r18/s1no1style-yua-mikami',
            CarbonImmutable::now(),
        );

        self::assertSame('SSNI-00916', $draft->code);
    }

    public function test_javbtc_product_code_in_url_is_normalized(): void
    {
        $normalizer = $this->registry()->forSlug('javbtc');
        $movie = new MovieDto(externalId: 'detail-page', title: 'A video title');

        $draft = $normalizer->normalizeMovie(
            $movie,
            [],
            'javbtc',
            'https://javbtc.com/r18/ssni00916',
            CarbonImmutable::now(),
        );

        self::assertSame('SSNI-00916', $draft->code);
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
