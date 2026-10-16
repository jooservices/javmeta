<?php

declare(strict_types=1);

namespace Tests\E2E\Steps;

use Closure;
use Tests\E2E\Context;
use Tests\E2E\Step;
use Tests\E2E\Support\CatalogSeed;

final class SeedCatalog implements Step
{
    /** @param Closure(): CatalogSeed $seed */
    public function __construct(private readonly Closure $seed) {}

    public function run(Context $context): Context
    {
        return $context->withCatalog(($this->seed)());
    }
}
