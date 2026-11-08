<?php

declare(strict_types=1);

namespace Tests\Unit\Data;

use App\Data\Api\MovieResourceData;
use PHPUnit\Framework\TestCase;

final class MovieResourceDataTest extends TestCase
{
    public function test_exports_movie_detail_contract_keys(): void
    {
        $data = new MovieResourceData(
            code: 'SSIS-001',
            uuid: '11111111-1111-1111-1111-111111111111',
            codes: [['code' => 'SSIS-001', 'kind' => 'dvd']],
            title_jp: 'Example title',
            description: 'Example description',
            performers: [['uuid' => null, 'id' => 1, 'name_romaji' => 'Aoi Example']],
            directors: ['Example Director'],
            cover_url: 'https://example.test/cover.jpg',
            cover_thumb_url: 'https://example.test/thumb.jpg',
            release_date: '2021-01-01',
            runtime_minutes: 120,
            maker: 'Example Maker',
            label: 'Example Label',
            series: 'Example Series',
            genres: ['Drama'],
            censored: true,
            community_score: 8.4,
            completeness_tier: 2,
            attrs: ['extra' => 'Example'],
            magnets: [['url' => 'magnet:?xt=urn:btih:example', 'crawled_at' => '2026-09-18T10:00:00Z']],
            hls_stream_urls: [['url' => 'https://example.test/stream.m3u8', 'crawled_at' => '2026-09-18T10:00:00Z']],
            gallery: [['url' => 'https://example.test/1.jpg', 'crawled_at' => '2026-09-18T10:00:00Z']],
            crawled_at: '2026-09-18T10:00:00Z',
        );

        self::assertSame([
            'uuid',
            'code',
            'codes',
            'title_jp',
            'title_en',
            'description',
            'performers',
            'directors',
            'cover_url',
            'cover_thumb_url',
            'release_date',
            'runtime_minutes',
            'maker',
            'label',
            'series',
            'genres',
            'censored',
            'community_score',
            'completeness_tier',
            'attrs',
            'magnets',
            'hls_stream_urls',
            'gallery',
            'photos',
            'crawled_at',
            'delisted_at',
            'needs_review',
        ], array_keys($data->toArray()));
        self::assertSame('SSIS-001', $data->toArray()['code']);
        self::assertArrayNotHasKey('provenance', $data->toArray());
    }
}
