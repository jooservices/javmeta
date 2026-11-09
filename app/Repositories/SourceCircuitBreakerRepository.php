<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Source;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/** Persistence operations for the per-source block circuit. */
final class SourceCircuitBreakerRepository extends BusinessQueryRepository
{
    public function __construct(Source $model)
    {
        parent::__construct($model);
    }

    /** @return array{opened: bool, consecutive_failures: int, open_count: int, cooldown_seconds: ?int, resume_at: ?DateTimeInterface}|null */
    public function recordBlock(string $slug, int $threshold, int $baseCooldown, int $maxCooldown): ?array
    {
        return $this->transaction(function () use ($slug, $threshold, $baseCooldown, $maxCooldown): ?array {
            $query = $this->modelQuery(Source::class)->whereKey($slug);
            if ($this->driverName() === 'pgsql') {
                $query->lockForUpdate();
            }
            $source = $query->first();
            if (! $source instanceof Source) {
                return null;
            }

            $failures = (int) $source->consecutive_failures + 1;
            $openCount = (int) $source->circuit_open_count;
            $isOpen = $source->circuit_state === 'open';
            $attributes = ['consecutive_failures' => $failures];
            $cooldown = $source->circuit_cooldown_seconds;
            $resumeAt = $source->circuit_opened_at === null || $cooldown === null
                ? null
                : CarbonImmutable::parse($source->circuit_opened_at)->addSeconds((int) $cooldown);

            if (! $isOpen && $failures >= max(1, $threshold)) {
                $openCount++;
                $cooldown = $this->exponentialCooldown($baseCooldown, $maxCooldown, $openCount);
                $openedAt = now();
                $resumeAt = $openedAt->copy()->addSeconds($cooldown);
                $attributes += [
                    'circuit_state' => 'open',
                    'circuit_opened_at' => $openedAt,
                    'circuit_open_count' => $openCount,
                    'circuit_cooldown_seconds' => $cooldown,
                ];
            }

            $this->modelQuery(Source::class)->whereKey($slug)->update($attributes);

            return [
                'opened' => ! $isOpen && $failures >= max(1, $threshold),
                'consecutive_failures' => $failures,
                'open_count' => $openCount,
                'cooldown_seconds' => $cooldown === null ? null : (int) $cooldown,
                'resume_at' => $resumeAt,
            ];
        });
    }

    public function resetFailures(string $slug): void
    {
        $this->modelQuery(Source::class)->whereKey($slug)->update(['consecutive_failures' => 0]);
    }

    /** Closes the circuit and clears the streak; returns the opening count when the circuit was open, so its alert can be resolved. */
    public function reset(string $slug): ?int
    {
        return $this->transaction(function () use ($slug): ?int {
            $query = $this->modelQuery(Source::class)->whereKey($slug);
            if ($this->driverName() === 'pgsql') {
                $query->lockForUpdate();
            }
            $source = $query->first(['slug', 'circuit_state', 'circuit_open_count']);
            if (! $source instanceof Source) {
                return null;
            }

            $this->modelQuery(Source::class)->whereKey($slug)->update([
                'consecutive_failures' => 0,
                'circuit_state' => 'closed',
                'circuit_opened_at' => null,
                'circuit_open_count' => 0,
                'circuit_cooldown_seconds' => null,
            ]);

            return $source->circuit_state === 'open' ? (int) $source->circuit_open_count : null;
        });
    }

    /**
     * Closes the circuits whose cooldown elapsed and lists the sources that are still paused.
     *
     * A circuit is closed only if it still carries the opening time that was read, so a block that reopened it
     * in the meantime is never cleared by this older view of the row.
     *
     * @return array{paused: list<string>, closed: list<array{slug: string, open_count: int}>}
     */
    public function syncPaused(): array
    {
        $paused = [];
        $closed = [];
        foreach ($this->modelQuery(Source::class)->where('circuit_state', 'open')->get(['slug', 'circuit_opened_at', 'circuit_cooldown_seconds', 'circuit_open_count']) as $source) {
            $rawOpenedAt = $source->getRawOriginal('circuit_opened_at');
            $openedAt = $source->circuit_opened_at === null ? null : CarbonImmutable::parse($source->circuit_opened_at);
            $cooldown = max(1, (int) ($source->circuit_cooldown_seconds ?? config('javmeta_sources.defaults.circuit.cooldown_seconds', 300)));
            if ($openedAt === null || $openedAt->addSeconds($cooldown)->isFuture()) {
                $paused[] = (string) $source->slug;
                continue;
            }

            $affected = $this->modelQuery(Source::class)
                ->whereKey($source->slug)
                ->where('circuit_state', 'open')
                ->where('circuit_opened_at', $rawOpenedAt)
                ->update(['circuit_state' => 'closed', 'circuit_opened_at' => null]);
            if ($affected > 0) {
                $closed[] = ['slug' => (string) $source->slug, 'open_count' => (int) $source->circuit_open_count];
            }
        }

        return ['paused' => $paused, 'closed' => $closed];
    }

    /** @return array<string, array{consecutive_failures: int, circuit_state: string, circuit_opened_at: ?DateTimeInterface, circuit_open_count: int, circuit_cooldown_seconds: ?int}> */
    public function statusBySlug(): array
    {
        $rows = [];
        foreach ($this->modelQuery(Source::class)->get(['slug', 'consecutive_failures', 'circuit_state', 'circuit_opened_at', 'circuit_open_count', 'circuit_cooldown_seconds']) as $source) {
            $openedAt = $source->circuit_opened_at === null ? null : CarbonImmutable::parse($source->circuit_opened_at);
            $rows[(string) $source->slug] = [
                'consecutive_failures' => (int) $source->consecutive_failures,
                'circuit_state' => (string) $source->circuit_state,
                'circuit_opened_at' => $openedAt,
                'circuit_open_count' => (int) $source->circuit_open_count,
                'circuit_cooldown_seconds' => $source->circuit_cooldown_seconds === null ? null : (int) $source->circuit_cooldown_seconds,
            ];
        }

        return $rows;
    }

    private function exponentialCooldown(int $baseCooldown, int $maxCooldown, int $openCount): int
    {
        $cooldown = max(1, $baseCooldown);
        $maximum = max($cooldown, $maxCooldown);
        for ($iteration = 1; $iteration < max(1, $openCount) && $cooldown < $maximum; $iteration++) {
            $cooldown = min($maximum, $cooldown * 2);
        }

        return $cooldown;
    }
}
