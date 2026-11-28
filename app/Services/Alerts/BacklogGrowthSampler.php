<?php

declare(strict_types=1);

namespace App\Services\Alerts;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository;

/**
 * Remembers how many items were due per source a while ago, so the alert can tell a backlog that grows from one that is only big.
 *
 * Samples live in the cache. When the cache is flushed the history starts again and no growth is reported until
 * {@see self::MINIMUM_HISTORY_SECONDS} have passed, so a flush can never raise a false alert.
 */
final class BacklogGrowthSampler
{
    private const KEY = 'javmeta:alerts:backlog-samples';

    /** A new sample is kept at most this often, so the baseline does not creep forward with every evaluation. */
    private const SAMPLE_EVERY_SECONDS = 300;

    /** Samples older than this are dropped. */
    private const KEEP_SECONDS = 4500;

    private const MINIMUM_HISTORY_SECONDS = 2700;

    /** The baseline is the sample whose age is nearest to this: growth is reported per hour. */
    private const TARGET_HISTORY_SECONDS = 3600;

    public function __construct(private readonly Repository $cache) {}

    /**
     * Records the due items of every source and returns how many items per hour each source gained since the oldest sample.
     *
     * @param array<string, int> $dueBySource
     * @return array<string, int> signed; empty while the history is shorter than 45 minutes
     */
    public function growthPerHour(array $dueBySource, CarbonImmutable $now): array
    {
        $at = $now->getTimestamp();
        $samples = array_values(array_filter(
            $this->samples(),
            static fn(array $sample): bool => $at - $sample['at'] <= self::KEEP_SECONDS,
        ));
        $baseline = $this->baseline($samples, $at);

        if ($samples === [] || $at - $samples[array_key_last($samples)]['at'] >= self::SAMPLE_EVERY_SECONDS) {
            $samples[] = ['at' => $at, 'due' => $dueBySource];
        }
        $this->cache->put(self::KEY, $samples, self::KEEP_SECONDS);

        if ($baseline === null) {
            return [];
        }

        $age = $at - $baseline['at'];
        $growth = [];
        foreach (array_keys($dueBySource + $baseline['due']) as $source) {
            $growth[(string) $source] = (int) round((($dueBySource[$source] ?? 0) - ($baseline['due'][$source] ?? 0)) * 3600 / $age);
        }

        return $growth;
    }

    /**
     * The sample nearest one hour old, as long as it is at least 45 minutes old.
     *
     * @param list<array{at: int, due: array<string, int>}> $samples
     * @return array{at: int, due: array<string, int>}|null
     */
    private function baseline(array $samples, int $at): ?array
    {
        $baseline = null;
        foreach ($samples as $sample) {
            $age = $at - $sample['at'];
            if ($age < self::MINIMUM_HISTORY_SECONDS) {
                continue;
            }
            if ($baseline === null || abs($age - self::TARGET_HISTORY_SECONDS) < abs($at - $baseline['at'] - self::TARGET_HISTORY_SECONDS)) {
                $baseline = $sample;
            }
        }

        return $baseline;
    }

    /** @return list<array{at: int, due: array<string, int>}> */
    private function samples(): array
    {
        $stored = $this->cache->get(self::KEY, []);

        return is_array($stored) ? array_values($stored) : [];
    }
}
