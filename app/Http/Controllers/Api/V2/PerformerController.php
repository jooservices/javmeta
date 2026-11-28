<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V2;

use App\Http\Api\V2\Envelope;
use App\Services\Api\V2\Catalogue\CatalogueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PerformerController
{
    public function __construct(private readonly CatalogueService $catalogue) {}

    public function index(Request $request): JsonResponse
    {
        $result = $this->catalogue->performers($request->query());

        return Envelope::success($result['data'], $result['meta']);
    }

    public function facets(Request $request): JsonResponse
    {
        return Envelope::success($this->catalogue->performerFacets($request->query()));
    }
}
