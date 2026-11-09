<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Resources;

use App\Data\Api\MovieResourceData;
use App\Http\Resources\MovieResource;
use App\Http\Resources\MovieSummaryResource;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

final class MovieResourceTest extends TestCase
{
    public function test_transforms_movie_resource_data_to_detail_json_keys(): void
    {
        $resource = new MovieResource(new MovieResourceData(
            code: 'SSIS-001',
            uuid: '11111111-1111-1111-1111-111111111111',
            title_jp: 'Example title',
            genres: ['Drama'],
            directors: ['Example Director'],
            completeness_tier: 1,
            crawled_at: '2026-09-18T10:00:00Z',
        ));

        $payload = $resource->toArray(Request::create('/api/v1/movies/SSIS-001'));

        self::assertSame('SSIS-001', $payload['code']);
        self::assertSame(['Drama'], $payload['genres']);
        self::assertSame(['Example Director'], $payload['directors']);
        self::assertArrayHasKey('performers', $payload);
        self::assertArrayNotHasKey('provenance', $payload);
        self::assertArrayHasKey('hls_stream_urls', $payload);
        self::assertArrayHasKey('description', $payload);
        self::assertArrayHasKey('attrs', $payload);
        self::assertArrayNotHasKey('actresses', $payload);
    }

    public function test_summary_resource_omits_detail_only_fields(): void
    {
        $resource = new MovieSummaryResource(new MovieResourceData(
            code: 'SSIS-001',
            uuid: '11111111-1111-1111-1111-111111111111',
            title_jp: 'Example title',
            performers: [['id' => 1, 'name_romaji' => 'Aoi']],
            magnets: [['url' => 'magnet:?xt=urn:btih:example', 'crawled_at' => null]],
            completeness_tier: 1,
        ));

        $payload = $resource->toArray(Request::create('/api/v1/movies'));

        self::assertSame('SSIS-001', $payload['code']);
        self::assertArrayHasKey('performers', $payload);
        self::assertArrayNotHasKey('magnets', $payload);
        self::assertArrayNotHasKey('hls_stream_urls', $payload);
        self::assertArrayNotHasKey('gallery', $payload);
        self::assertArrayNotHasKey('provenance', $payload);
        self::assertArrayNotHasKey('codes', $payload);
        self::assertArrayNotHasKey('description', $payload);
        self::assertArrayNotHasKey('attrs', $payload);
    }

    public function test_summary_uses_japanese_title_and_short_derived_code_with_opt_in_raw_code(): void
    {
        $code = 'JAVBTC-DERIVED-8e9952' . str_repeat('a', 58);
        $resource = new MovieSummaryResource(new MovieResourceData(
            code: $code,
            title_jp: '日本語の作品',
            title_en: '',
        ));

        $safe = $resource->toArray(Request::create('/api/v1/search'));
        $raw = $resource->toArray(Request::create('/api/v1/search?include_internal_code=1'));

        self::assertSame('日本語の作品', $safe['title_en']);
        self::assertSame('JAVBTC · #8E9952', $safe['code']);
        self::assertArrayNotHasKey('internal_code', $safe);
        self::assertSame($code, $raw['internal_code']);
    }
}
