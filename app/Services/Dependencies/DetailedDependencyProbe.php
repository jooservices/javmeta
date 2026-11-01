<?php

declare(strict_types=1);

namespace App\Services\Dependencies;

interface DetailedDependencyProbe extends DependencyProbe
{
    public function check(): ProbeResult;
}
