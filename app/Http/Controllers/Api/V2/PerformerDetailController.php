<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V2;

use App\Http\Api\V2\Envelope;
use App\Http\Requests\Api\V2\PerformerLookupRequest;
use App\Http\Requests\Api\V2\PerformerMoviesRequest;
use App\Services\Api\V2\Catalogue\PerformerDetails;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PerformerDetailController
{
    public function __construct(private readonly PerformerDetails $performers) {}

    public function movieFacets(Request $request): JsonResponse
    {
        return Envelope::success($this->performers->movieFacets($request->query()));
    }

    public function facetValues(Request $request): JsonResponse
    {
        return Envelope::success($this->performers->facetValues($request->query()));
    }

    public function lookups(PerformerLookupRequest $request): JsonResponse
    {
        return Envelope::success($this->performers->lookup(array_map(intval(...), $request->validated('ids'))));
    }

    public function show(string $id): JsonResponse
    {
        $profile = $this->performers->profile($id);

        return Envelope::success($profile['data'], ['canonical_slug' => $profile['slug']]);
    }

    public function sources(string $id): JsonResponse
    {
        return Envelope::success($this->performers->sources($id));
    }

    public function photos(string $id): JsonResponse
    {
        return Envelope::success($this->performers->photos($id));
    }

    public function coPerformers(Request $request, string $id): JsonResponse
    {
        return Envelope::success($this->performers->coPerformers($id, $request->query()));
    }

    public function statistics(string $id): JsonResponse
    {
        return Envelope::success($this->performers->statistics($id));
    }

    public function movies(PerformerMoviesRequest $request, string $id): JsonResponse
    {
        $result = $this->performers->movies($id, $request->query());

        return Envelope::success($result['data'], $result['meta']);
    }
}
