<?php

declare(strict_types=1);

namespace Tests\E2E\Steps;

use Closure;
use Tests\E2E\Context;
use Tests\E2E\Step;
use Tests\E2E\Support\ApiKeyCredential;

final class ResetTestState implements Step
{
    /** @param Closure(): list<ApiKeyCredential> $reset */
    public function __construct(private readonly Closure $reset) {}

    public function run(Context $context): Context
    {
        foreach (($this->reset)() as $credential) {
            $context = $context->withCreatedKey($credential);
        }

        return $context;
    }
}
