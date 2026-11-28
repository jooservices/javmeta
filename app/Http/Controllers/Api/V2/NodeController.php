<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V2;

use App\Http\Api\V2\Envelope;
use App\Http\Controllers\Api\V2\Concerns\KnowsTheActor;
use App\Http\Requests\Api\V2\StoreNodeEnrollmentRequest;
use App\Services\Api\V2\Operations\NodeDirectory;
use Illuminate\Http\JsonResponse;

final class NodeController
{
    use KnowsTheActor;

    public function __construct(private readonly NodeDirectory $nodes) {}

    public function index(): JsonResponse
    {
        return Envelope::success($this->nodes->all());
    }

    public function show(string $id): JsonResponse
    {
        return Envelope::success($this->nodes->find($id));
    }

    public function enrollments(): JsonResponse
    {
        return Envelope::success($this->nodes->enrollments());
    }

    public function enroll(StoreNodeEnrollmentRequest $request): JsonResponse
    {
        $grant = $this->nodes->enroll((string) $request->input('node_name'), (string) $request->input('role', 'worker'), $this->actorId());

        return Envelope::success($grant, status: 201, headers: ['Location' => "/api/v2/node-enrollments/{$grant['id']}", 'Cache-Control' => 'no-store, private']);
    }

    public function revoke(int $id): JsonResponse
    {
        $this->nodes->revoke($id, $this->actorId());

        return Envelope::empty();
    }
}
