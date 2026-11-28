<?php

declare(strict_types=1);

namespace App\Services\Api\V2\Discovery;

use App\Http\Api\V2\Envelope;
use App\Http\Api\V2\ProblemException;
use App\Repositories\Api\V2\UpdatesRepository;
use App\Services\Api\V2\Catalogue\Formatter;
use Closure;
use Illuminate\Support\Carbon;

/** What was collected recently (by day), highlights and the release calendar. */
final class UpdatesService
{
    private const SUMMARY_SECONDS = 60;

    private const HIGHLIGHT_SECONDS = 120;

    public function __construct(private readonly UpdatesRepository $repository, private readonly Formatter $formatter, private readonly ReadCache $cache) {}

    /**
     * @param array<string, mixed> $query
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function updates(array $query, int $page, int $perPage): array
    {
        $offset = ($page - 1) * $perPage;
        $type = (string) ($query['type'] ?? 'movies');

        [$result, $shape] = match ($type) {
            'movies' => [$this->repository->recentMovies($perPage, $offset), $this->formatter->movie(...)],
            'performers' => [$this->repository->recentPerformers(($query['sort'] ?? null) === 'debut', ($query['has_photo'] ?? null) === '1', $perPage, $offset), $this->formatter->performer(...)],
            'photos' => [$this->repository->recentPhotos($perPage, $offset), $this->avatar(...)],
            default => throw ProblemException::badRequest('invalid_type', 'type must be movies, performers or photos'),
        };

        return ['data' => $this->byDay($result['rows'], $shape), 'meta' => Envelope::pagination($page, $perPage, $result['total'])];
    }

    /** @return array<string, int> */
    public function summary(): array
    {
        return $this->cache->remember('updates-summary', self::SUMMARY_SECONDS, fn(): array => array_map('intval', (array) $this->repository->summary()));
    }

    /** @return array<string, list<array<string, mixed>>> */
    public function highlights(): array
    {
        return $this->cache->remember('highlights', self::HIGHLIGHT_SECONDS, fn(): array => [
            'picks' => array_map($this->formatter->movie(...), $this->repository->picks()),
            'hot_performers' => array_map(fn(object $row): array => $this->formatter->performer($row) + ['recent_movies' => (int) $row->recent], $this->repository->hotPerformers()),
            'newest' => array_map($this->formatter->movie(...), $this->repository->newest()),
        ]);
    }

    /**
     * One day's releases, or the number of releases of each day of a month (default: the current month).
     *
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function calendar(?string $month, ?string $day): array
    {
        if ($day !== null && $day !== '') {
            return ['data' => array_map($this->formatter->movie(...), $this->repository->releasedOn($day)), 'meta' => []];
        }

        $month = $month === null || $month === '' ? Carbon::now('UTC')->format('Y-m') : $month;

        return ['data' => array_map(static fn(object $row): array => ['day' => $row->day, 'count' => (int) $row->count], $this->repository->releasesOfMonth($month)), 'meta' => ['month' => $month]];
    }

    /**
     * Rows in their order, grouped under the UTC day they were collected.
     *
     * @param list<object> $rows
     * @param Closure(object): array<string, mixed> $shape
     * @return list<array<string, mixed>>
     */
    private function byDay(array $rows, Closure $shape): array
    {
        $days = [];

        foreach ($rows as $row) {
            $days[(string) $row->day][] = $shape($row);
        }

        $out = [];

        foreach ($days as $day => $items) {
            $out[] = ['day' => (string) $day, 'total' => count($items), 'items' => $items];
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function avatar(object $row): array
    {
        return ['kind' => 'avatar', 'thumb_url' => $row->photo_url, 'full_url' => $row->photo_url, 'label' => $row->name_romaji, 'performer_id' => (int) $row->id];
    }
}
