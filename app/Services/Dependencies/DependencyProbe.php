<?php

declare(strict_types=1);

namespace App\Services\Dependencies;

interface DependencyProbe
{
    public function probe(): bool;
}
