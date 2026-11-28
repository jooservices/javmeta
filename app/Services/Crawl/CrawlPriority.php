<?php

declare(strict_types=1);

namespace App\Services\Crawl;

/**
 * Priority of a crawl_queue row in the direct lane: high, normal or low, served in that order by the same workers.
 *
 * The level is computed when a row is claimed, from the settings, so a change of a rule applies to the rows that are already
 * queued. A source listed as high is high, a source listed as low is low, a recrawl is low while recrawl_is_low is on, and every
 * other row (new work of any other source) is normal.
 */
final class CrawlPriority
{
    public const HIGH = 'high';

    public const NORMAL = 'normal';

    public const LOW = 'low';

    /** @var list<string> in the order they are served; the position is the rank the SQL expression returns */
    public const LEVELS = [self::HIGH, self::NORMAL, self::LOW];

    /**
     * SQL expression that gives a crawl_queue row the rank of its level (0 high, 1 normal, 2 low), with its bindings.
     *
     * @return array{string, list<string>}
     */
    public function rankExpression(): array
    {
        $high = $this->sources('high_sources');
        $low = $this->sources('low_sources');
        $sql = 'CASE';
        $bindings = [];
        if ($high !== []) {
            $sql .= ' WHEN source_slug IN (' . $this->placeholders($high) . ') THEN ' . $this->rank(self::HIGH);
            $bindings = [...$bindings, ...$high];
        }
        if ($low !== []) {
            $sql .= ' WHEN source_slug IN (' . $this->placeholders($low) . ') THEN ' . $this->rank(self::LOW);
            $bindings = [...$bindings, ...$low];
        }
        if ((bool) config('javmeta_queues.priority.recrawl_is_low', true)) {
            $sql .= ' WHEN is_recrawl IS TRUE THEN ' . $this->rank(self::LOW);
        }

        // With no rule every row is normal, and CASE without a WHEN is not valid SQL.
        return $sql === 'CASE' ? [(string) $this->rank(self::NORMAL), []] : [$sql . ' ELSE ' . $this->rank(self::NORMAL) . ' END', $bindings];
    }

    /**
     * The levels that have a cap, with it: the most rows of that level that may be claimed and unfinished at the same time.
     *
     * @return array<string, int> level => cap, only for a cap above zero
     */
    public function caps(): array
    {
        $caps = [];
        foreach (self::LEVELS as $level) {
            $cap = max(0, (int) config('javmeta_queues.priority.caps.' . $level, 0));
            if ($cap > 0) {
                $caps[$level] = $cap;
            }
        }

        return $caps;
    }

    public function rank(string $level): int
    {
        return (int) array_search($level, self::LEVELS, true);
    }

    /** @return list<string> */
    private function sources(string $key): array
    {
        return array_values(array_unique(array_filter((array) config('javmeta_queues.priority.' . $key, []), static fn(mixed $slug): bool => is_string($slug) && $slug !== '')));
    }

    /** @param list<string> $values */
    private function placeholders(array $values): string
    {
        return implode(', ', array_fill(0, count($values), '?'));
    }
}
