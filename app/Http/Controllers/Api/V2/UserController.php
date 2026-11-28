<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V2;

use App\Http\Api\V2\Envelope;
use App\Http\Requests\Api\V2\StoreUserRequest;
use App\Http\Requests\Api\V2\UpdateUserRequest;
use App\Services\Api\V2\UserDirectory;
use Illuminate\Http\JsonResponse;

final class UserController
{
    public function __construct(private readonly UserDirectory $users) {}

    public function index(): JsonResponse
    {
        return Envelope::success(array_map(static fn($user): array => $user->toArray(), $this->users->all()));
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $user = $this->users->create((string) $request->input('name'), (string) $request->input('email'), (string) $request->input('password'), (string) $request->input('role', 'user'));

        return Envelope::success($user->toArray(), status: 201, headers: ['Location' => "/api/v2/users/{$user->id}"]);
    }

    public function update(UpdateUserRequest $request, int $id): JsonResponse
    {
        return Envelope::success($this->users->update($id, $request->validated())->toArray());
    }

    public function destroy(int $id): JsonResponse
    {
        $this->users->delete($id);

        return Envelope::empty();
    }
}
