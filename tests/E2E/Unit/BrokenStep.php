<?php

declare(strict_types=1);

namespace Tests\E2E\Unit;

use Tests\E2E\Context;
use Tests\E2E\Step;
use Tests\E2E\Support\StepFailure;

final class BrokenStep implements Step
{
    public function run(Context $context): Context
    {
        throw new StepFailure('Request leaked jvm_super-secret.', $context);
    }
}
