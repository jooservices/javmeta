<?php

declare(strict_types=1);

namespace Tests\E2E\Steps;

use Closure;
use Tests\E2E\Context;
use Tests\E2E\Step;
use Tests\E2E\Support\FixtureSeed;

final class SeedFixtureSource implements Step
{
    /** @param Closure(): FixtureSeed $seed */
    public function __construct(private readonly Closure $seed) {}

    public function run(Context $context): Context
    {
        return $context->withFixture(($this->seed)());
    }
}
