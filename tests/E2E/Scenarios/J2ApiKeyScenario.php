<?php

declare(strict_types=1);

namespace Tests\E2E\Scenarios;

use Closure;
use Tests\E2E\Scenario;
use Tests\E2E\Step;
use Tests\E2E\Steps\Authenticate;
use Tests\E2E\Steps\CreateApiKey;
use Tests\E2E\Steps\ListApiKeys;
use Tests\E2E\Steps\ResetTestState;
use Tests\E2E\Steps\RevokeApiKey;
use Tests\E2E\Support\ApiKeyCredential;

final class J2ApiKeyScenario extends Scenario
{
    /** @param Closure(): list<ApiKeyCredential> $reset */
    public function __construct(private readonly Closure $reset)
    {
        parent::__construct();
    }

    protected function name(): string
    {
        return 'J2 operator creates, authenticates, and revokes an API key';
    }

    /** @return list<Step> */
    protected function steps(): array
    {
        return [
            new ResetTestState($this->reset),
            new CreateApiKey(),
            new ListApiKeys(),
            new Authenticate(),
            new RevokeApiKey(),
            new Authenticate(401),
        ];
    }
}
