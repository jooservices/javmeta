<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Sources;

use App\Support\Sources\SourceParityChecker;
use PHPUnit\Framework\TestCase;

final class SourceParityCheckerTest extends TestCase
{
    private SourceParityChecker $checker;

    protected function setUp(): void
    {
        $this->checker = new SourceParityChecker();
    }

    public function test_matching_sources_have_no_problems(): void
    {
        $problems = $this->checker->problems(
            ['a' => ['listing', 'detail'], 'b' => ['performer_listing', 'performer_detail'], 'c' => ['gallery']],
            ['a' => ['movie_listing_urls' => ['x']], 'b' => ['performer_listing_urls' => ['x']], 'c' => ['gallery_urls' => ['x']]],
        );

        self::assertSame([], $problems);
    }

    public function test_an_adapter_without_a_source_and_a_source_without_an_adapter_are_reported_by_slug(): void
    {
        $problems = $this->checker->problems(['new_site' => ['listing', 'detail']], ['old_site' => ['movie_listing_urls' => ['x']]]);

        self::assertContains('new_site: crawlerx adapter has no javmeta source in config/javmeta_sources.php', $problems);
        self::assertContains('old_site: javmeta source has no crawlerx adapter', $problems);
    }

    public function test_a_declared_capability_without_configured_urls_is_a_problem(): void
    {
        $problems = $this->checker->problems(
            ['a' => ['listing', 'detail', 'performer_listing'], 'g' => ['gallery']],
            ['a' => ['movie_listing_urls' => ['x']], 'g' => []],
        );

        self::assertSame([
            'a: crawlerx declares performer support but config has no performer_listing_urls',
            'g: crawlerx declares gallery support but config has no gallery_urls|gallery_listing_urls',
        ], $problems);
    }

    public function test_configured_urls_for_an_undeclared_capability_are_a_problem(): void
    {
        $problems = $this->checker->problems(
            ['a' => ['listing', 'detail']],
            ['a' => ['movie_listing_urls' => ['x'], 'performer_listing_urls' => ['y'], 'gallery_listing_urls' => ['z']]],
        );

        self::assertSame([
            'a: config sets gallery_urls|gallery_listing_urls but crawlerx does not declare that capability',
            'a: config sets performer_listing_urls but crawlerx does not declare that capability',
        ], $problems);
    }

    public function test_a_listing_without_detail_is_not_a_movie_source(): void
    {
        $problems = $this->checker->problems(
            ['photos' => ['listing', 'gallery']],
            ['photos' => ['gallery_listing_urls' => ['x']]],
        );

        self::assertSame([], $problems);
    }

    public function test_a_documented_known_gap_is_tolerated_but_a_stale_one_is_reported(): void
    {
        $capabilities = ['m' => ['listing', 'detail', 'performer_listing']];
        $gap = ['m' => [SourceParityChecker::MOVIE => 'POC seeds performers only']];

        self::assertSame([], $this->checker->problems($capabilities, ['m' => ['performer_listing_urls' => ['x']]], $gap));

        $stale = $this->checker->problems($capabilities, ['m' => ['movie_listing_urls' => ['x'], 'performer_listing_urls' => ['y']]], $gap);
        self::assertSame(['m: known gap for movie_listing_urls is stale (POC seeds performers only); remove it'], $stale);
    }
}
