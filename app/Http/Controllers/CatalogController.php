<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\CatalogRequest;
use App\Services\Catalog\CatalogService;
use Illuminate\Http\JsonResponse;
use JOOservices\LaravelController\Http\Controllers\BaseApiController;

final class CatalogController extends BaseApiController
{
    public function index(CatalogRequest $request, CatalogService $catalog): JsonResponse
    {
        $result = $catalog->index($request->catalogQuery());

        return $this->respondWithData(
            data: $result->items,
            message: 'Catalog retrieved successfully.',
            meta: ['pagination' => $result->pagination],
        );
    }
}
