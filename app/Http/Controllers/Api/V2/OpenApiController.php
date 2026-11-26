<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V2;

use Illuminate\Http\JsonResponse;
use Symfony\Component\Yaml\Yaml;

/** Serves the v2 contract (docs/api/openapi.yaml) as JSON. */
final class OpenApiController
{
    public function __invoke(): JsonResponse
    {
        /** @var array<string, mixed> $spec */
        $spec = Yaml::parseFile(base_path('docs/api/openapi.yaml'));

        return new JsonResponse($spec);
    }
}
