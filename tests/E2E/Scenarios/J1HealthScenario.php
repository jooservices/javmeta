<?php

declare(strict_types=1);

namespace Tests\E2E\Scenarios;

use Tests\E2E\Step;
use Tests\E2E\Steps\StartStack;

final class J1HealthScenario extends FixtureScenario
{
    protected function name(): string
    {
        return 'J1 operator starts the stack and checks health';
    }

    /** @return list<Step> */
    protected function steps(): array
    {
        return [$this->resetStep(), ...$this->fixtureSteps(), new StartStack()];
    }
}
