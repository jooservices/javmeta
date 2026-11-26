<?php

declare(strict_types=1);

namespace Tests\Unit\Api\V2;

use App\Http\Api\V2\Paging;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PagingTest extends TestCase
{
    /** @return iterable<string, array{array<string, mixed>, array{int, int}}> */
    public static function queries(): iterable
    {
        yield 'nothing given uses the default size on the first page' => [[], [1, 12]];
        yield 'both given' => [['page' => '3', 'per_page' => '20'], [3, 20]];
        yield 'size above the maximum is clamped' => [['per_page' => '5000'], [1, 48]];
        yield 'size zero falls back to the default' => [['per_page' => '0'], [1, 12]];
        yield 'negative size is raised to one' => [['per_page' => '-7'], [1, 1]];
        yield 'page zero is the first page' => [['page' => '0'], [1, 12]];
        yield 'negative page is the first page' => [['page' => '-4'], [1, 12]];
        yield 'text is read as zero' => [['page' => 'abc', 'per_page' => 'x'], [1, 12]];
        yield 'an array value is not a number' => [['page' => ['1'], 'per_page' => ['2']], [1, 1]];
    }

    /**
     * @param array<string, mixed> $query
     * @param array{int, int} $expected
     */
    #[DataProvider('queries')]
    public function test_page_and_size_are_read_and_clamped(array $query, array $expected): void
    {
        $this->assertSame($expected, Paging::read($query, 12, 48));
    }
}
