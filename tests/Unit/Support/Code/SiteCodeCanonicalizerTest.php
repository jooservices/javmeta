<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Code;

use App\Support\Code\NormalizedCode;
use App\Support\Code\SiteCodeCanonicalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SiteCodeCanonicalizerTest extends TestCase
{
    #[DataProvider('resolveProvider')]
    public function test_resolves_site_codes(string $slug, array $candidates, string $expected): void
    {
        $resolved = SiteCodeCanonicalizer::resolve($slug, $candidates);

        self::assertSame($expected, $resolved);
        self::assertNotNull($resolved);
        NormalizedCode::from($resolved);
    }

    /**
     * @return list<array{string, list<string|null>, string}>
     */
    public static function resolveProvider(): array
    {
        return [
            ['onepondo', ['060426_001'], '1PONDO-060426_001'],
            ['caribbeancom', ['080826-001'], 'CARIBBEANCOM-080826-001'],
            ['10musume', ['110326_01'], '10MUSUME-110326-01'],
            ['muramura', ['103126_1273'], 'MURAMURA-103126-1273'],
            ['pacopacomama', ['110326_100'], 'PACOPACOMAMA-110326-100'],
            ['caribbeancompr', ['060126_001'], 'CARIBBEANCOM-060126-001'],
            ['heyzo', ['3927'], 'HEYZO-3927'],
            ['heyzo', ['HEYZO-3927'], 'HEYZO-3927'],
            ['tokyohot', ['n1508'], 'TOKYOHOT-1508'],
            ['tokyohot', ['5372'], 'TOKYOHOT-5372'],
            ['fc2', ['1234567'], 'FC2-PPV-1234567'],
            ['xcity', ['MXDLP0185'], 'MXDLP-0185'],
            ['xcity', ['202424'], 'XCITY-202424'],
            ['xcity', ['XCT-186022'], 'XCITY-186022'],
            ['javdb', ['a8yV0p', 'SONE-763 普段は…'], 'SONE-763'],
            ['javdb', ['SONE-763'], 'SONE-763'],
            ['onejav', ['ymds282', 'YMDS-282 Studio Title'], 'YMDS-282'],
            ['onejav', ['908jdh004'], '908JDH-004'],
            ['onejav', ['huntc572'], 'HUNTC-572'],
            ['onejav', ['dsod114'], 'DSOD-114'],
            ['missav', ['SSIS-001'], 'SSIS-001'],
            ['jable', ['FJIN-091'], 'FJIN-091'],
        ];
    }

    public function test_returns_null_when_no_valid_candidate(): void
    {
        self::assertNull(SiteCodeCanonicalizer::resolve('javdb', ['a8yV0p', 'opaque']));
        self::assertNull(SiteCodeCanonicalizer::resolve('missav', [null, '', '!!!']));
    }
}
