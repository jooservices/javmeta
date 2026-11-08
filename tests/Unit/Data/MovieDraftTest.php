<?php

declare(strict_types=1);

namespace Tests\Unit\Data;

use App\Data\Crawl\PerformerDraft;
use App\Data\Crawl\MovieDraft;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class MovieDraftTest extends TestCase
{
    public function test_creates_movie_draft_with_normalized_contract_fields(): void
    {
        $releaseDate = CarbonImmutable::parse('2021-01-01');
        $crawledAt = CarbonImmutable::parse('2026-09-18T10:00:00Z');
        $performer = new PerformerDraft('javdb', 'actor-1', nameRomaji: 'Aoi Example');

        $draft = MovieDraft::from(
            sourceSlug: 'javdb',
            sourceUrl: 'https://example.test/v/ssis001',
            code: 'ssis-001',
            titleJp: 'Example title',
            releaseDate: $releaseDate,
            runtimeMinutes: 120,
            censored: true,
            genres: ['Drama'],
            performers: [$performer],
            coverUrl: 'https://example.test/cover.jpg',
            extras: [
                'magnets' => [['url' => 'magnet:?xt=urn:btih:example']],
                'hls' => [['url' => 'https://example.test/stream.m3u8']],
                'gallery' => [['url' => 'https://example.test/1.jpg']],
                'score' => 8.4,
            ],
            crawledAt: $crawledAt,
        );

        self::assertSame('javdb', $draft->sourceSlug);
        self::assertSame('ssis-001', $draft->code);
        self::assertSame(120, $draft->runtimeMinutes);
        self::assertSame(['Drama'], $draft->genres);
        self::assertSame([$performer], $draft->performers);
        self::assertSame(8.4, $draft->extras['score']);
        self::assertSame($releaseDate, $draft->releaseDate);
        self::assertSame($crawledAt, $draft->crawledAt);
    }

    public function test_rejects_empty_code(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new MovieDraft('javdb', 'https://example.test/v/1', '');
    }

    public function test_rejects_invalid_runtime(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new MovieDraft('javdb', 'https://example.test/v/1', 'SSIS-001', runtimeMinutes: 0);
    }

    public function test_it_rejects_extras_when_a_media_reference_collection_is_missing(): void
    {
        $this->expectExceptionMessage('Movie extras magnets must be a list.');

        new MovieDraft(
            'javdb',
            'https://example.test/v/1',
            'SSIS-001',
            extras: ['hls' => [], 'gallery' => [], 'score' => null],
        );
    }

    public function test_it_rejects_integer_score_instead_of_the_normalized_float(): void
    {
        $this->expectExceptionMessage('Movie extras score must be a float or null.');

        new MovieDraft(
            'javdb',
            'https://example.test/v/1',
            'SSIS-001',
            extras: ['magnets' => [], 'hls' => [], 'gallery' => [], 'score' => 8],
        );
    }
}
