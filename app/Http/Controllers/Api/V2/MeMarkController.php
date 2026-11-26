<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V2;

use App\Http\Api\V2\Caller;
use App\Http\Api\V2\Envelope;
use App\Http\Requests\Api\V2\MeListRequest;
use App\Services\Api\V2\Me\Marks;
use Illuminate\Http\JsonResponse;

final class MeMarkController
{
    public function __construct(private readonly Marks $marks) {}

    public function index(MeListRequest $request): JsonResponse
    {
        $result = $this->marks->list(Caller::userId(), $request->validated());

        return Envelope::success($result['data'], $result['meta']);
    }

    public function movies(MeListRequest $request): JsonResponse
    {
        $result = $this->marks->movies(Caller::userId(), $request->validated());

        return Envelope::success($result['data'], $result['meta']);
    }

    public function show(string $kind, string $target, string $ref): JsonResponse
    {
        return Envelope::success($this->marks->state(Caller::userId(), $kind, $target, $ref));
    }

    public function put(string $kind, string $target, string $ref): JsonResponse
    {
        return Envelope::success($this->marks->put(Caller::userId(), $kind, $target, $ref));
    }

    public function destroy(string $kind, string $target, string $ref): JsonResponse
    {
        $this->marks->remove(Caller::userId(), $kind, $target, $ref);

        return Envelope::empty();
    }
}
