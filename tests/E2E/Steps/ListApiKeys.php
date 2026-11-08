<?php

declare(strict_types=1);

namespace Tests\E2E\Steps;

use Tests\E2E\Context;
use Tests\E2E\Support\HttpStep;
use Tests\E2E\Support\StepFailure;

final class ListApiKeys extends HttpStep
{
    public function run(Context $context): Context
    {
        $credential = $this->credential($context);
        $result = $this->exchange($context, 'GET', '/api/v1/admin/keys', ['X-Owner-Token' => $context->ownerToken]);
        $context = $result->context;
        $this->expectStatus($context, $result->response, 200);
        $keys = $result->response->json('data');

        if (! is_array($keys)) {
            throw new StepFailure('The owner key list was not an array.', $context);
        }

        $listed = collect($keys)->firstWhere('id', $credential->id);
        if (! is_array($listed) || ($listed['status'] ?? null) !== 'active' || array_key_exists('plaintext', $listed)) {
            throw new StepFailure('The owner key list omitted the active key or exposed plaintext.', $context);
        }

        return $context;
    }
}
