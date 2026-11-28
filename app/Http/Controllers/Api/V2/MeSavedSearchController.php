<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V2;

use App\Http\Api\V2\Caller;
use App\Http\Api\V2\Envelope;
use App\Http\Requests\Api\V2\SaveSearchRequest;
use App\Http\Requests\Api\V2\UpdateSavedSearchRequest;
use App\Services\Api\V2\Me\SavedSearches;
use Illuminate\Http\JsonResponse;

final class MeSavedSearchController
{
    public function __construct(private readonly SavedSearches $searches) {}

    public function index(): JsonResponse
    {
        return Envelope::success($this->searches->list(Caller::userId()));
    }

    public function store(SaveSearchRequest $request): JsonResponse
    {
        $search = $this->searches->create(Caller::userId(), (string) $request->input('name'), (string) $request->input('query'), (string) $request->input('target', 'movie'));

        return Envelope::success($search, status: 201, headers: ['Location' => '/api/v2/me/saved-searches/' . $search['id']]);
    }

    public function update(UpdateSavedSearchRequest $request, int $id): JsonResponse
    {
        $name = $request->has('name') ? (string) $request->input('name') : null;
        $query = $request->has('query') ? (string) $request->input('query') : null;

        return Envelope::success($this->searches->update(Caller::userId(), $id, $name, $query));
    }

    public function destroy(int $id): JsonResponse
    {
        $this->searches->delete(Caller::userId(), $id);

        return Envelope::empty();
    }
}
