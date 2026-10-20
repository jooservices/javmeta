<?php

declare(strict_types=1);

namespace Tests\E2E\Steps;

use Tests\E2E\Context;
use Tests\E2E\Support\HttpStep;

final class RevokeApiKey extends HttpStep
{
    public function __construct(private readonly string $purpose = 'consumer') {}

    public function run(Context $context): Context
    {
        $credential = $this->credential($context, $this->purpose);
        $result = $this->exchange($context, 'DELETE', '/api/v1/admin/keys/' . $credential->id, ['X-Owner-Token' => $context->ownerToken]);
        $this->expectStatus($result->context, $result->response, 204);

        return $result->context;
    }
}
