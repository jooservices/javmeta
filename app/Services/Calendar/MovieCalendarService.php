<?php

declare(strict_types=1);

namespace App\Services\Calendar;

use App\Data\Calendar\MovieCalendarSnapshot;
use App\Exceptions\InvalidFilterException;
use App\Repositories\MovieRepository;
use Illuminate\Support\Facades\Cache;

/** Builds and caches the release calendar view from repository data. */
final class MovieCalendarService
{
    public function __construct(private readonly MovieRepository $movies) {}

    public function get(?string $requestedMonth): MovieCalendarSnapshot
    {
        if ($requestedMonth === '') {
            $requestedMonth = null;
        }
        if ($requestedMonth !== null && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $requestedMonth) !== 1) {
            throw new InvalidFilterException('Invalid month.', ['month' => ['YYYY-MM']]);
        }

        $latestMonth = $this->latestMonth();
        $month = $requestedMonth ?? $latestMonth;
        /** @var list<array{date: string, count: int, movies: list<array{code: ?string, title: ?string, cover_url: ?string, maker: ?string}>}> $days */
        $days = Cache::remember('javmeta:calendar:' . $month, 120, fn(): array => $this->load($month));

        return new MovieCalendarSnapshot($month, array_sum(array_column($days, 'count')), $latestMonth, $days);
    }

    private function latestMonth(): string
    {
        $latest = $this->movies->latestReleaseDate();

        return is_string($latest) && preg_match('/^\d{4}-(0[1-9]|1[0-2])/', $latest) === 1
            ? substr($latest, 0, 7)
            : now()->format('Y-m');
    }

    /** @return list<array{date: string, count: int, movies: list<array{code: ?string, title: ?string, cover_url: ?string, maker: ?string}>}> */
    private function load(string $month): array
    {
        $byDay = [];
        foreach ($this->movies->releaseCalendarRows($month) as $row) {
            $date = substr($row['release_date'], 0, 10);
            $byDay[$date] ??= ['date' => $date, 'count' => 0, 'movies' => []];
            $byDay[$date]['count']++;
            if (count($byDay[$date]['movies']) < 8) {
                $byDay[$date]['movies'][] = [
                    'code' => $row['display_code'],
                    'title' => $row['title_en'] ?? $row['title_jp'],
                    'cover_url' => $row['cover_thumb_url'] ?? $row['cover_url'],
                    'maker' => $row['maker'],
                ];
            }
        }

        return array_values($byDay);
    }
}
