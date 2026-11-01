<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Dependencies\DependencyProbe;

final class MutableDependencyProbe implements DependencyProbe
{
    public int $calls = 0;

    /** @var list<bool> */
    private array $results;

    public function __construct(bool|array $results = true)
    {
        $this->results = is_array($results) ? array_values($results) : [$results];
    }

    public function setResults(bool|array $results): void
    {
        $this->results = is_array($results) ? array_values($results) : [$results];
    }

    public function probe(): bool
    {
        $this->calls++;

        return $this->results === [] ? true : (bool) (count($this->results) > 1 ? array_shift($this->results) : $this->results[0]);
    }
}
