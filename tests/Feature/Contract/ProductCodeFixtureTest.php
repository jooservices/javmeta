<?php

declare(strict_types=1);

namespace Tests\Feature\Contract;

use App\Models\CrawlQueue;
use App\Models\Movie;
use App\Models\MovieCode;
use App\Support\Code\NormalizedCode;
use JOOservices\Client\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DomCrawler\Crawler;

final class ProductCodeFixtureTest extends CrawlerxContractTestCase
{
    /** @return array<string, array{string, string, string, string}> */
    public static function fixtures(): array
    {
        return [
            'javbtc' => ['javbtc', 'https://javbtc.com/r18/softondemand-marina-shiraishi-atmania-stepmom', 'javbtc', 'JAVBTC-DERIVED-' . strtoupper(hash('sha256', '/r18/softondemand-marina-shiraishi-atmania-stepmom'))],
            'tokyohot invasion' => ['tokyohot', 'https://my.tokyo-hot.com/product/Japanese_Invasion_2_America_Sc4/', 'tokyohot-invasion', 'TOKYOHOT-SLUG-JAPANESE_INVASION_2_AMERICA_SC4'],
            'tokyohot soapland' => ['tokyohot', 'https://my.tokyo-hot.com/product/Shemalesoapland_Sc4/', 'tokyohot-soapland', 'TOKYOHOT-SLUG-SHEMALESOAPLAND_SC4'],
            'ffjav' => ['ffjav', 'https://ffjav.com/torrent/10musume-052425_01-%e5%a4%a9%e7%84%b6%e3%82%80%e3%81%99%e3%82%81-052425_01-%e7%94%9f%e4%b8%ad%e5%87%ba%e3%81%97%e3%82%92%e8%b3%ad%e3%81%91%e3%81%9f%e9%87%8e%e7%90%83%e6%8b%b3%e3%82%b2%e3%83%bc', 'ffjav', '10MUSUME-052425_01'],
            '141jav' => ['141jav', 'https://www.141jav.com/torrent/C2897', '141jav', 'C-2897'],
        ];
    }

    #[DataProvider('fixtures')]
    public function test_captured_page_is_stored_and_recrawl_updates_it(string $slug, string $url, string $fixture, string $code): void
    {
        $this->source($slug);
        $row = $this->claimedRow($slug, $url, CrawlQueue::KIND_DETAIL);
        $path = __DIR__ . '/../../Fixtures/product-codes/' . $fixture . '.html';
        $this->respondWithFixture($url, $path);
        $this->dispatchFor($row);
        self::assertSame(CrawlQueue::STATUS_DONE, $row->refresh()->status, (string) $row->last_error);
        $movie = Movie::query()->sole();
        self::assertSame(NormalizedCode::from($code)->value(), $movie->getAttribute('code_normalized'));
        self::assertNotEmpty($movie->getAttribute('title_en') ?? $movie->getAttribute('title_jp'));

        $titleColumn = $movie->getAttribute('title_en') !== null ? 'title_en' : 'title_jp';
        $title = $movie->getAttribute($titleColumn);
        $crawler = new Crawler((string) file_get_contents($path));
        $selector = match ($slug) {
            'javbtc' => 'div.a8 + div.a6 a',
            'tokyohot' => '#main .contents > h2',
            default => 'h5.title a',
        };
        $node = $crawler->filter($selector)->first()->getNode(0);
        self::assertNotNull($node);
        $node->textContent = rtrim($node->textContent) . ' updated';
        $trackedUrl = $url . '?campaign=SSIS-001&id=SONE-763#YMDS-282';
        $this->travel(1)->minutes();
        $row->update(['status' => CrawlQueue::STATUS_CLAIMED, 'url' => $trackedUrl]);
        foreach ([$url, $trackedUrl, explode('#', $trackedUrl, 2)[0]] as $fetchUrl) {
            $this->respondWith($fetchUrl, TestResponse::make(200, ['Content-Type' => 'text/html'], $crawler->html()));
        }
        $this->dispatchFor($row);
        self::assertSame(CrawlQueue::STATUS_DONE, $row->refresh()->status, (string) $row->last_error);
        self::assertSame(1, Movie::query()->count());
        self::assertSame(1, MovieCode::query()->count());
        self::assertSame($movie->getKey(), Movie::query()->sole()->getKey());
        self::assertSame($title . ' updated', $movie->refresh()->getAttribute($titleColumn));
    }
}
