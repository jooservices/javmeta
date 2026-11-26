<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V2;

use App\Http\Api\V2\Envelope;
use App\Http\Requests\Api\V2\MovieLookupRequest;
use App\Http\Requests\Api\V2\MovieViewsRequest;
use App\Services\Api\V2\Catalogue\MovieDetails;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use stdClass;

final class MovieDetailController
{
    public function __construct(private readonly MovieDetails $movies) {}

    public function facetValues(Request $request): JsonResponse
    {
        return Envelope::success($this->movies->facetValues($request->query()));
    }

    public function lookups(MovieLookupRequest $request): JsonResponse
    {
        $result = $this->movies->lookup(array_map(strval(...), $request->validated('codes')));

        return Envelope::success($result['data'], ['missing' => $result['missing']]);
    }

    public function views(MovieViewsRequest $request): JsonResponse
    {
        return Envelope::success($this->movies->viewCounts((string) $request->validated('codes')) ?: new stdClass());
    }

    public function show(string $code): JsonResponse
    {
        return Envelope::success($this->movies->detail($code));
    }

    public function recordView(string $code): JsonResponse
    {
        $userId = Auth::guard('web')->id();
        $count = $this->movies->recordView($code, $userId === null ? null : (int) $userId);

        return Envelope::success(['count' => $count], status: 201, headers: ['Location' => '/api/v2/movies/' . rawurlencode($code)]);
    }

    public function codes(string $code): JsonResponse
    {
        $result = $this->movies->codes($code);

        return Envelope::success($result['data'], ['markers' => $result['markers']]);
    }

    public function sources(string $code): JsonResponse
    {
        return Envelope::success($this->movies->sources($code));
    }

    public function media(string $code): JsonResponse
    {
        return Envelope::success($this->movies->media($code));
    }

    public function downloads(string $code): JsonResponse
    {
        return Envelope::success($this->movies->downloads($code));
    }

    public function related(Request $request, string $code): JsonResponse
    {
        return Envelope::success($this->movies->related($code, $request->query()));
    }
}
