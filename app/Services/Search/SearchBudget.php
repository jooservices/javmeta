<?php

declare(strict_types=1);

namespace App\Services\Search;

use Closure;

/** A monotonic deadline shared by optional HTTP work in one hybrid search. */
final class SearchBudget
{
    private ?int $deadline = null;

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
        $previous = $this->deadline;
        $this->deadline = ($this->clock)() + max(1, (int) config('javmeta_search.budget_ms', 300)) * 1_000_000;
        try {
            return $work();
        } finally {
            $this->deadline = $previous;
        }
    }

    public function seconds(float $maximum): float
    {
        return $this->deadline === null ? $maximum : min($maximum, max(0, ($this->deadline - ($this->clock)()) / 1e9));
    }

    public function expired(): bool
    {
        return $this->seconds(1) <= 0;
    }
}
