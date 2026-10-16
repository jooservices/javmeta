<?php

declare(strict_types=1);

namespace Tests\E2E\Steps;

use Tests\E2E\Context;
use Tests\E2E\Support\HttpStep;
use Tests\E2E\Support\StepFailure;

final class Authenticate extends HttpStep
{
    public function __construct(private readonly int $expectedStatus = 200, private readonly string $purpose = 'consumer') {}

    public function run(Context $context): Context
    {
        $credential = $this->credential($context, $this->purpose);
        $result = $this->exchange($context, 'GET', '/api/v1/auth/verify', ['X-Api-Key' => $credential->plaintext]);
        $context = $result->context;
        $this->expectStatus($context, $result->response, $this->expectedStatus);

        if ($this->expectedStatus === 401 && $result->response->json('data') !== null) {
            throw new StepFailure('An unauthorized response included catalog data.', $context);
        }

        if ($this->expectedStatus === 200 && $result->response->json('data.status') !== 'ok') {
            throw new StepFailure('The authenticated response did not report success.', $context);
        }

        return $context;
    }
}
