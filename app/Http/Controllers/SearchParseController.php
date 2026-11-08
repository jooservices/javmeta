<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\SearchParseRequest;
use App\Services\Search\SearchParseService;
use App\Services\Search\SearchParseTelemetry;
use Illuminate\Http\JsonResponse;
use JOOservices\LaravelController\Http\Controllers\BaseApiController;

final class SearchParseController extends BaseApiController
{
    public function __invoke(SearchParseRequest $request, SearchParseService $search): JsonResponse
    {
        $telemetry = new SearchParseTelemetry();

        try {
            $result = $search->parse((string) $request->validated('q'), $telemetry);

            return $this->respondWithData(data: $result->toArray(), message: 'Natural-language query parsed successfully.');
        } finally {
            // The request log reads the application request, not the validated copy a FormRequest works on.
            request()->attributes->set('javmeta.search_parse.telemetry', $telemetry->toArray());
        }
    }
}
