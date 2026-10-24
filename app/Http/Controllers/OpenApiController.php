<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

/** Serves the published API contract (resources/openapi/javmeta.json). */
final class OpenApiController
{
    public function __invoke(): JsonResponse
    {
        $spec = json_decode((string) file_get_contents(resource_path('openapi/javmeta.json')), true, 512, JSON_THROW_ON_ERROR);

        return response()->json($spec);
    }
}
