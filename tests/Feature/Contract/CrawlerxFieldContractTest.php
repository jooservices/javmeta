<?php

declare(strict_types=1);

namespace Tests\Feature\Contract;

use App\Models\CrawlQueue;
use App\Models\Movie;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Values, not just "stored": the movie columns that CrawlerX 1.4 fills for a
 * site must reach the movies table. Guards against a renamed CrawlerX key or a
 * wrong key map silently storing NULL.
 */
final class CrawlerxFieldContractTest extends CrawlerxContractTestCase
{
    /** @var array<string, array<string, mixed>> fixture "slug|file" => expected movie columns / minimum media */
    private const EXPECTED = [
        'duga|detail.html' => ['maker' => 'V&Rプランニング', 'label' => 'V&R PANDEMIC', 'community_score' => 10.0],
        'javbus|detail-namh-074-live.html' => ['maker' => 'なまなま', 'label' => 'なまなま', 'series' => '至高の膣内射精', 'magnet' => 9],
        'javdb|detail.html' => ['maker' => 'S1 NO.1 STYLE', 'magnet' => 18],
        'javlibrary|detail-javme3tuqi-rated.html' => ['maker' => 'Honnaka', 'label' => 'Honnaka', 'community_score' => 9.0],
        'missav|detail-fns-247.html' => ['maker' => 'FALENO', 'label' => 'FALENO star'],
        'xcity|detail-208587.html' => ['maker' => 'ホットエンターテイメント', 'label' => 'ホット'],
        'tokyohot|detail.html' => ['maker' => 'Tokyo-Hot', 'label' => 'Tokyo-Hot'],
        'ffjav|detail-sample-1.html' => ['maker' => 'ムーディーズ', 'label' => 'MOODYZ DIVA', 'magnet' => 1],
        'onepondo|detail-060426_001.json' => ['maker' => '1Pondo', 'series' => 'Hotel Concierge', 'community_score' => 10.0],
        '10musume|detail-110326_01.json' => [
            'code_normalized' => '10MUSUME110326001',
            'title_en' => 'Pussy to Mouth Sex: Saki Konno',
            'release_date' => '2026-11-03',
            'performer_romaji' => 'Saki Konno',
            'performer_kanji' => '紺野咲',
            'genre' => 'Amateur',
        ],
        'muramura|detail-103126_1273.json' => [
            'code_normalized' => 'MURAMURA1031261273',
            'title_en' => 'Wireless Rotor Walk : Ayaka Inoue',
            'release_date' => '2026-10-31',
            'performer_romaji' => 'Ayaka Inoue',
            'performer_kanji' => '井上あやか',
            'genre' => 'Amateur',
        ],
        'pacopacomama|detail-110326_100.json' => [
            'code_normalized' => 'PACOPACOMAMA110326100',
            'title_en' => 'Fucking a a Married Woman Who Endlessly Repeats “PtoM” Until We\'re Both Exhausted',
            'release_date' => '2026-11-03',
            'performer_romaji' => 'Momoko Akasaka',
            'performer_kanji' => '赤坂桃子',
            'genre' => 'Milf',
        ],
        'caribbeancompr|detail.html' => [
            'code_normalized' => 'CARIBBEANCOM060126001',
            'title_en' => 'We got frisky in Kimono before heading out to the summer festival',
            'release_date' => '2026-06-01',
            'performer_romaji' => 'Mirina Nozomi',
            'genre' => 'Amateur',
        ],
        'minnanoav|movie-detail.html' => ['maker' => 'エスワン ナンバーワンスタイル', 'label' => 'S1 NO.1 STYLE'],
        'fc2|detail.html' => ['community_score' => 10.0],
        'heyzo|detail.html' => ['community_score' => 9.0],
    ];

    /** @return array<string, array{string, string, string, string}> */
    public static function fixtures(): array
    {
        return array_filter(
            self::crawlerxFixtures(CrawlQueue::KIND_DETAIL),
            static fn(array $fixture): bool => isset(self::EXPECTED[$fixture[0] . '|' . basename($fixture[3])]),
        );
    }

    public function test_every_expectation_has_a_fixture(): void
    {
        $present = array_map(static fn(array $f): string => $f[0] . '|' . basename($f[3]), self::crawlerxFixtures(CrawlQueue::KIND_DETAIL));

        self::assertSame([], array_values(array_diff(array_keys(self::EXPECTED), $present)));
    }

    #[DataProvider('fixtures')]
    public function test_movie_columns_hold_the_values_crawlerx_returned(string $slug, string $kind, string $url, string $bodyPath): void
    {
        $this->source($slug);
        $row = $this->claimedRow($slug, $url, $kind);
        $this->respondWithFixture($url, $bodyPath);
        $this->dispatchFor($row);

        $movie = Movie::query()->firstOrFail();
        foreach (self::EXPECTED[$slug . '|' . basename($bodyPath)] as $column => $expected) {
            if ($column === 'magnet') {
                $count = DB::table('movie_media')->where('movie_id', $movie->id)->where('kind', 'magnet')->count();
                self::assertGreaterThanOrEqual($expected, $count, "{$slug}: magnets stored");

                continue;
            }

            if ($column === 'performer_romaji' || $column === 'performer_kanji') {
                $nameColumn = $column === 'performer_romaji' ? 'name_romaji' : 'name_kanji';
                self::assertTrue(
                    $movie->performers()->where($nameColumn, $expected)->exists(),
                    "{$slug}: performer {$nameColumn} stored",
                );

                continue;
            }

            if ($column === 'genre') {
                self::assertTrue(
                    $movie->genres()->where('label_normalized', mb_strtolower($expected))->exists(),
                    "{$slug}: genre stored",
                );

                continue;
            }

            if ($column === 'release_date') {
                self::assertSame($expected, $movie->release_date?->toDateString(), "{$slug}: release_date");

                continue;
            }

            $actual = $movie->getAttribute($column);
            self::assertEquals($expected, is_numeric($expected) ? (float) $actual : $actual, "{$slug}: {$column}");
        }
    }
}
