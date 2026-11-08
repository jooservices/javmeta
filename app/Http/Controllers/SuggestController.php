<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\SuggestRequest;
use App\Services\Suggest\SuggestService;
use Illuminate\Http\JsonResponse;
use JOOservices\LaravelController\Http\Controllers\BaseApiController;

final class SuggestController extends BaseApiController
{
    public function index(SuggestRequest $request, SuggestService $suggestions): JsonResponse
    {
        return $this->respondWithData(
            data: $suggestions->suggest($request->suggestQuery())->toArray(),
            message: 'Suggestions retrieved successfully.',
        );
    }
}
