<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Code;

use App\Support\Code\NormalizedCode;
use App\Support\Code\SiteCodeCanonicalizer;
use PHPUnit\Framework\TestCase;

final class SourceScopedCodeTest extends TestCase
{
    public function test_fallback_identity_ignores_tracking_and_trailing_slash_but_distinguishes_source_and_path(): void
    {
        $codes = [];
        foreach (['javbtc' => 'r18', 'ffjav' => 'torrent', '141jav' => 'torrent', 'tokyohot' => 'product'] as $slug => $prefix) {
            $base = 'https://example.test/' . $prefix;
            $code = SiteCodeCanonicalizer::resolve($slug, [null], $base . '/no-code');
            self::assertNotNull($code);
            self::assertSame($code, SiteCodeCanonicalizer::resolve($slug, [], $base . '/no-code/?a=1#part'));
            self::assertNotSame($code, SiteCodeCanonicalizer::resolve($slug, [], $base . '/other'));
            self::assertNull(SiteCodeCanonicalizer::resolve($slug, [], 'https://example.test/category/other'));
            self::assertNull(SiteCodeCanonicalizer::resolve($slug, [], $base . '?id=123'));
            self::assertSame($code, NormalizedCode::from($code)->value());
            $codes[] = $code;
        }
        self::assertCount(4, array_unique($codes));
        self::assertNull(SiteCodeCanonicalizer::resolve('missav', [], 'https://example.test/product/no-code'));
        self::assertNull(SiteCodeCanonicalizer::resolve('javbtc', [], 'https://example.test/'));
    }

    public function test_existing_standard_codes_take_precedence_over_derived_codes(): void
    {
        foreach (['javbtc', 'ffjav', '141jav', 'tokyohot'] as $slug) {
            self::assertSame('SSNI-00916', SiteCodeCanonicalizer::resolve($slug, ['SSNI-00916'], 'https://example.test/no-code'));
        }
    }

    public function test_scene_slugs_with_the_same_scene_number_remain_distinct(): void
    {
        $one = SiteCodeCanonicalizer::resolve('tokyohot', ['Japanese_Invasion_2_America_Sc4']);
        $two = SiteCodeCanonicalizer::resolve('tokyohot', ['Shemalesoapland_Sc4']);
        self::assertNotNull($one);
        self::assertNotNull($two);
        self::assertNotSame(NormalizedCode::from($one)->value(), NormalizedCode::from($two)->value());
    }
}
