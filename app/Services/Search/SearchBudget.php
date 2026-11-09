<?php

declare(strict_types=1);

namespace App\Services\Search;

use Closure;

/** A monotonic deadline shared by one search request. */
final class SearchBudget
{
    private ?int $deadline = null;

    private ?int $phaseDeadline = null;

    /** @var list<string> */
    private array $degraded = [];

    /** @var Closure(): int */
    private readonly Closure $clock;

    public function __construct(?Closure $clock = null)
    {
        $this->clock = $clock ?? static fn(): int => hrtime(true);
    }

    /** @template T
     * @param Closure(): T $work
     * @return T
     */
    public function run(Closure $work): mixed
    {
        if ($this->active()) {
            return $work();
        }

        $configured = (int) config('javmeta_search.budget_ms', 8000);
        $this->deadline = ($this->clock)() + min(10_000, max(1, $configured)) * 1_000_000;
        $this->phaseDeadline = null;
        $this->degraded = [];
        try {
            return $work();
        } finally {
            $this->deadline = null;
            $this->phaseDeadline = null;
            $this->degraded = [];
        }
    }

    public function seconds(float $maximum): float
    {
        $deadline = $this->effectiveDeadline();

        return $deadline === null ? $maximum : min($maximum, max(0, ($deadline - ($this->clock)()) / 1e9));
    }

    public function expired(): bool
    {
        return $this->seconds(1) <= 0;
    }

    public function active(): bool
    {
        return $this->deadline !== null;
    }

    public function statementTimeoutMilliseconds(int $maximum = 2000): int
    {
        $deadline = $this->effectiveDeadline();
        if ($deadline === null) {
            return $maximum;
        }

        return max(0, min($maximum, intdiv(max(0, $deadline - ($this->clock)()), 1_000_000)));
    }

    /** @template T
     * @param Closure(): T $work
     * @return T
     */
    public function within(int $milliseconds, Closure $work): mixed
    {
        $previous = $this->phaseDeadline;
        $deadline = ($this->clock)() + max(0, $milliseconds) * 1_000_000;
        $parent = $this->effectiveDeadline();
        $this->phaseDeadline = $parent === null ? $deadline : min($parent, $deadline);

        try {
            $result = $work();
            if ($this->expired()) {
                throw new SearchDeadlineExceeded();
            }

            return $result;
        } finally {
            $this->phaseDeadline = $previous;
        }
    }

    public function remainingMilliseconds(): int
    {
        $deadline = $this->effectiveDeadline();

        return $deadline === null ? PHP_INT_MAX : max(0, intdiv(max(0, $deadline - ($this->clock)()), 1_000_000));
    }

    private function effectiveDeadline(): ?int
    {
        if ($this->deadline === null) {
            return $this->phaseDeadline;
        }

        return $this->phaseDeadline === null ? $this->deadline : min($this->deadline, $this->phaseDeadline);
    }

    public function degrade(string $reason): void
    {
        if (! in_array($reason, $this->degraded, true)) {
            $this->degraded[] = $reason;
        }
    }

    /** @return list<string> */
    public function degraded(): array
    {
        return $this->degraded;
    }
}
