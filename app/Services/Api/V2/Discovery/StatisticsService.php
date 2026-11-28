<?php

declare(strict_types=1);

namespace App\Services\Api\V2\Discovery;

use App\Http\Api\V2\ProblemException;
use App\Repositories\Api\V2\StatisticsRepository;
use Carbon\CarbonImmutable;

/** The numbers behind the statistics screens. Every answer is shared and short-lived cached. */
final class StatisticsService
{
    private const SHORT = 120;

    private const LONG = 300;

    private const KINDS = ['makers' => 'maker', 'series' => 'series', 'labels' => 'label', 'genres' => 'genre'];

    public function __construct(private readonly StatisticsRepository $repository, private readonly ReadCache $cache) {}

    /** @return array<string, mixed> */
    public function overview(): array
    {
        return $this->cache->remember('stat-overview', self::SHORT, function (): array {
            $row = (array) $this->repository->overview();
            $row['data_as_of'] = $this->time($row['data_as_of']);

            return $row + ['quality_tiers' => array_map(static fn(object $r): array => ['tier' => $r->tier === null ? null : (int) $r->tier, 'count' => (int) $r->count], $this->repository->qualityTiers())];
        });
    }

    /**
     * @return array{data: list<array{period: string, count: int}>, meta: array{metric: string, interval: string}}
     */
    public function series(string $metric, string $interval, int $days): array
    {
        $metric = $metric === 'performers' ? 'performers' : 'movies';
        $interval = in_array($interval, ['hour', 'month'], true) ? $interval : 'day';
        $days = min(730, max(1, $days));
        $rows = $this->cache->remember("stat-series-{$metric}-{$interval}-{$days}", self::LONG, fn(): array => array_map(static fn(object $r): array => ['period' => (string) $r->period, 'count' => (int) $r->count], $this->repository->series($metric === 'performers' ? 'rm_performers' : 'rm_movies', $interval, $days)));

        return ['data' => $rows, 'meta' => ['metric' => $metric, 'interval' => $interval]];
    }

    /**
     * @return array{data: list<array{label: string, count: int}>, meta: array{by: string}}
     */
    public function distribution(string $by, int $limit): array
    {
        if (! in_array($by, $this->repository->distributionNames(), true)) {
            throw ProblemException::badRequest('invalid_distribution', 'by must be ' . implode(', ', $this->repository->distributionNames()));
        }

        $limit = min(60, max(1, $limit));
        $rows = $this->cache->remember("stat-dist-{$by}-{$limit}", self::LONG, fn(): array => array_map(static fn(object $r): array => ['label' => (string) $r->label, 'count' => (int) $r->count], $this->repository->distribution($by, $limit)));

        return ['data' => $rows, 'meta' => ['by' => $by]];
    }

    /**
     * @return array{data: list<array<string, mixed>>, meta: array{pair: string}}
     */
    public function correlation(string $pair): array
    {
        if (! in_array($pair, $this->repository->correlationNames(), true)) {
            throw ProblemException::badRequest('invalid_pair', 'pair must be ' . implode(', ', $this->repository->correlationNames()));
        }

        $rows = $this->cache->remember("stat-corr-{$pair}", self::LONG, fn(): array => array_map(static fn(object $r): array => ['x' => is_numeric($r->x) ? (int) $r->x : (string) $r->x, 'y' => (float) $r->y, 'n' => (int) $r->n], $this->repository->correlation($pair)));

        return ['data' => $rows, 'meta' => ['pair' => $pair]];
    }

    /** @return array<string, int> */
    public function dataQuality(): array
    {
        return $this->cache->remember('stat-dq', self::LONG, fn(): array => array_map('intval', (array) $this->repository->dataQuality()));
    }

    /** @return list<array<string, mixed>> */
    public function crawlHealth(int $hours): array
    {
        $hours = min(168, max(1, $hours));

        return $this->cache->remember("stat-health-{$hours}", self::SHORT, fn(): array => array_map(fn(object $r): array => ['hour_at' => $this->time($r->hour_at), 'ok' => (int) $r->ok, 'attempts' => (int) $r->attempts, 'blocked' => (int) $r->blocked, 'timeout' => (int) $r->timeout, 'errors' => (int) $r->errors], $this->repository->crawlHealth($hours)));
    }

    /** @return list<array{slug: string, name: string, n: int}> */
    public function contributions(): array
    {
        return $this->cache->remember('stat-contrib', self::SHORT, fn(): array => array_map(static fn(object $r): array => ['slug' => (string) $r->slug, 'name' => (string) $r->name, 'n' => (int) $r->n], $this->repository->contributions()));
    }

    /** @return array{active: int, total: int} */
    public function sourcesActive(): array
    {
        return $this->cache->remember('stat-src-active', 60, function (): array {
            $row = $this->repository->sourcesActive();

            return ['active' => (int) $row->active, 'total' => (int) $row->total];
        });
    }

    /** @return array<string, mixed> */
    public function dashboard(): array
    {
        return $this->cache->remember('stat-dashboard', self::LONG, fn(): array => json_decode((string) json_encode($this->repository->dashboard(), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR));
    }

    /**
     * Seven axes (0 to 100) for 2 or 3 entries of a kind, each measured against the whole catalogue.
     *
     * @return list<array<string, mixed>>
     */
    public function compare(string $kind, string $names): array
    {
        $list = array_slice(array_values(array_filter(explode(',', $names), static fn(string $n): bool => $n !== '')), 0, 3);

        if (! isset(self::KINDS[$kind]) || count($list) < 2) {
            throw ProblemException::badRequest('invalid_compare', 'kind (makers|series|labels|genres) and 2-3 names are required');
        }

        $found = $this->repository->compare(self::KINDS[$kind], $list);
        $axis = static fn(?float $share, int $scale): int => $share === null ? 0 : (int) round($share * $scale);

        return array_map(static fn(object $r): array => [
            'name' => (string) $r->name,
            'n' => (int) $r->n,
            'share' => $r->n / max(1, $found['total']),
            'axes' => [
                'scale' => (int) round(sqrt($r->n / max(1, $found['max'])) * 100),
                'tier3' => $axis($r->tier3, 100),
                'score' => $axis($r->score, 10),
                'links' => $axis($r->links, 100),
                'watch' => $axis($r->watch, 100),
                'cast' => $axis($r->cast_share, 100),
                'recent' => $axis($r->recent, 100),
            ],
        ], $found['rows']);
    }

    private function time(?string $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse($value)->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
