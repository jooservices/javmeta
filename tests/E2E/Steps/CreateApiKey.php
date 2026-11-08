<?php

declare(strict_types=1);

namespace Tests\E2E\Steps;

use Tests\E2E\Context;
use Tests\E2E\Support\ApiKeyCredential;
use Tests\E2E\Support\HttpStep;
use Tests\E2E\Support\StepFailure;

final class CreateApiKey extends HttpStep
{
    /** The scopes the journeys call with this key: movie lookup and search, bulk lookup and performer profiles. */
    private const SCOPES = ['movies', 'bulk', 'performers'];

    public function __construct(private readonly string $label = 'E2E consumer') {}

    public function run(Context $context): Context
    {
        $headers = ['X-Owner-Token' => $context->ownerToken];

        // A key without scopes must be refused, so the journeys never run against an unscoped key.
        $refused = $this->exchange($context, 'POST', '/api/v1/admin/keys', $headers, ['label' => $this->label, 'scopes' => []]);
        $context = $refused->context;
        $this->expectStatus($context, $refused->response, 422);

        $result = $this->exchange($context, 'POST', '/api/v1/admin/keys', $headers, ['label' => $this->label, 'scopes' => self::SCOPES]);
        $context = $result->context;
        $this->expectStatus($context, $result->response, 201);
        $id = $result->response->json('data.id');
        $plaintext = $result->response->json('data.plaintext');

        if (! is_numeric($id) || ! is_string($plaintext) || ! str_starts_with($plaintext, 'jvm_')) {
            throw new StepFailure('The API key response did not contain an id and recognizable key.', $context);
        }

        return $context->withCreatedKey(new ApiKeyCredential((int) $id, $plaintext));
    }
}
