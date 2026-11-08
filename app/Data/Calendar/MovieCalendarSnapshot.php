<?php

declare(strict_types=1);

namespace App\Data\Calendar;

/** A month of release days and the latest month that contains a release. */
final readonly class MovieCalendarSnapshot
{
    /**
     * @param list<array{
     *     date: string,
     *     count: int,
     *     movies: list<array{code: ?string, title: ?string, cover_url: ?string, maker: ?string}>
     * }> $days
     */
    public function __construct(
        public string $month,
        public int $total,
        public string $latestMonth,
        public array $days,
    ) {}

    /** @return array{month: string, total: int, latest_month: string} */
    public function meta(): array
    {
        return [
            'month' => $this->month,
            'total' => $this->total,
            'latest_month' => $this->latestMonth,
        ];
    }
}
