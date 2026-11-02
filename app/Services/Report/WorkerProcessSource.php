<?php

declare(strict_types=1);

namespace App\Services\Report;

interface WorkerProcessSource
{
    /** @return list<array{pid: int, lane: string}> */
    public function processes(): array;
}
