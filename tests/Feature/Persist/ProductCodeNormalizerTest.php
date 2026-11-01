<?php

declare(strict_types=1);

namespace Tests\Feature\Persist;

use App\Models\Movie;
use App\Models\MovieCode;
use App\Services\Normalize\SourceNormalizerRegistry;
use App\Services\Persist\MoviePersister;
use App\Support\Code\NormalizedCode;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use JOOservices\CrawlerX\Dto\Entity\MovieDto;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class ProductCodeNormalizerTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{string, string, ?string, string}> */
    public static function products(): array
    {
        return [
            'ffjav uncensored' => ['ffjav', 'https://ffjav.com/torrent/10musume-052425_01-example/', null, '10MUSUME-052425_01'],
            '141jav short prefix' => ['141jav', 'https://www.141jav.com/torrent/C2897', 'C2897', 'C-2897'],
            'tokyohot scene one' => ['tokyohot', 'https://my.tokyo-hot.com/product/Japanese_Invasion_2_America_Sc4/', 'Japanese_Invasion_2_America_Sc4', 'TOKYOHOT-SLUG-JAPANESE_INVASION_2_AMERICA_SC4'],
            'tokyohot scene two' => ['tokyohot', 'https://my.tokyo-hot.com/product/Shemalesoapland_Sc4/', 'Shemalesoapland_Sc4', 'TOKYOHOT-SLUG-SHEMALESOAPLAND_SC4'],
            'javbtc no product' => ['javbtc', 'https://javbtc.com/r18/softondemand-marina-shiraishi-atmania-stepmom', null, 'JAVBTC-DERIVED-' . strtoupper(hash('sha256', '/r18/softondemand-marina-shiraishi-atmania-stepmom'))],
        ];
    }

    #[DataProvider('products')]
    public function test_normalizes_and_updates_the_same_movie_on_recrawl(string $slug, string $url, ?string $raw, string $expected): void
    {
        $normalizer = app(SourceNormalizerRegistry::class)->forSlug($slug);
        $movie = new MovieDto(code: $raw, title: 'Product title', externalId: basename(rtrim($url, '/')));
        $draft = $normalizer->normalizeMovie($movie, [], $slug, $url, CarbonImmutable::parse('2026-11-01'));
        self::assertSame($expected, $draft->code);
        $persister = app(MoviePersister::class);
        $first = $persister->persist($draft);
        $recrawl = $normalizer->normalizeMovie($movie, [], $slug, $url . '?tracking=second', CarbonImmutable::parse('2026-11-02'));
        $second = $persister->persist($recrawl, isRecrawl: true);
        self::assertSame($first->getKey(), $second->getKey());
        self::assertSame(1, Movie::query()->count());
        self::assertSame(1, MovieCode::query()->count());
        self::assertSame(NormalizedCode::from($expected)->value(), $second->getAttribute('code_normalized'));
    }
}
