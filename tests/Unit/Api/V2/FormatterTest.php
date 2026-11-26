<?php

declare(strict_types=1);

namespace Tests\Unit\Api\V2;

use App\Services\Api\V2\Catalogue\Formatter;
use PHPUnit\Framework\TestCase;
use Tests\Support\Api\V2\ReadModelRows;

final class FormatterTest extends TestCase
{
    private Formatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new Formatter();
    }

    public function test_a_movie_row_becomes_the_contract_summary_with_typed_values(): void
    {
        $movie = $this->formatter->movie((object) ReadModelRows::movie());

        self::assertSame('AAA-001', $movie['code']);
        self::assertSame('Title', $movie['title']);
        self::assertSame(4.5, $movie['score']);
        self::assertSame(['Drama', 'Big, Bold'], $movie['genres']);
        self::assertSame([7, 8], $movie['performer_ids']);
        self::assertSame(['alpha', 'beta'], $movie['sources']);
        self::assertSame([3, 1, 2], [$movie['download_count'], $movie['hls_count'], $movie['gallery_count']]);
        self::assertSame('https://img/t.jpg', $movie['cover_thumb_url']);
        self::assertSame(date('c', (int) strtotime('2024-05-02 10:00:00')), $movie['crawled_at']);
        self::assertFalse($movie['needs_review']);
        self::assertFalse($movie['delisted']);
    }

    public function test_a_movie_falls_back_to_the_japanese_title_the_full_cover_and_keeps_nulls(): void
    {
        $movie = $this->formatter->movie((object) ReadModelRows::movie([
            'title_en' => '', 'cover_thumb_url' => null, 'score' => null, 'crawled_at' => null, 'genres' => null, 'performer_ids' => '{}', 'sources' => '', 'needs_review' => 1, 'delisted' => 1,
        ]));

        self::assertSame('タイトル', $movie['title']);
        self::assertSame('https://img/c.jpg', $movie['cover_thumb_url']);
        self::assertNull($movie['score']);
        self::assertNull($movie['crawled_at']);
        self::assertSame([], $movie['genres']);
        self::assertSame([], $movie['performer_ids']);
        self::assertSame([], $movie['sources']);
        self::assertTrue($movie['needs_review']);
        self::assertTrue($movie['delisted']);
    }

    public function test_a_performer_row_gets_a_slug_an_age_and_optionally_the_shared_movie_count(): void
    {
        $performer = $this->formatter->performer((object) ReadModelRows::performer());

        self::assertSame(7, $performer['id']);
        self::assertSame('7-yui-hatano', $performer['slug']);
        self::assertSame('Yui Hatano', $performer['name']);
        self::assertSame(12, $performer['movie_count']);
        self::assertSame(['Yui'], $performer['aliases']);
        self::assertGreaterThanOrEqual(37, $performer['age']);
        self::assertArrayNotHasKey('together', $performer);

        $together = $this->formatter->performer((object) ReadModelRows::performer(['together' => '5']));
        self::assertSame(5, $together['together']);
    }

    public function test_a_performer_without_a_birth_date_has_no_age(): void
    {
        $performer = $this->formatter->performer((object) ReadModelRows::performer(['birth_date' => null, 'aliases' => '{}', 'crawled_at' => null, 'first_seen_at' => null]));

        self::assertNull($performer['age']);
        self::assertSame([], $performer['aliases']);
        self::assertNull($performer['crawled_at']);
        self::assertNull($performer['first_seen_at']);
    }

    public function test_time_is_iso_8601_or_null(): void
    {
        self::assertNull($this->formatter->time(null));
        self::assertSame(date('c', (int) strtotime('2024-01-02 03:04:05')), $this->formatter->time('2024-01-02 03:04:05'));
    }
}
