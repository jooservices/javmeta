<?php

declare(strict_types=1);

namespace Tests\E2E;

interface Step
{
    public function run(Context $context): Context;
}
