<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V2;

use App\Http\Api\V2\Caller;
use App\Http\Api\V2\Envelope;
use App\Services\Api\V2\Me\Follows;
use Illuminate\Http\JsonResponse;

final class MeFollowController
{
    public function __construct(private readonly Follows $follows) {}

    public function index(): JsonResponse
    {
        return Envelope::success($this->follows->list(Caller::userId()));
    }

    public function show(string $kind, string $ref): JsonResponse
    {
        return Envelope::success($this->follows->state(Caller::userId(), $kind, $ref));
    }

    public function put(string $kind, string $ref): JsonResponse
    {
        return Envelope::success($this->follows->put(Caller::userId(), $kind, $ref));
    }

    public function destroy(string $kind, string $ref): JsonResponse
    {
        $this->follows->remove(Caller::userId(), $kind, $ref);

        return Envelope::empty();
    }
}
