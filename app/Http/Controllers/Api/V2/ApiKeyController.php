<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V2;

use App\Http\Api\V2\Envelope;
use App\Http\Requests\Api\V2\ListApiKeysRequest;
use App\Http\Requests\Api\V2\StoreApiKeyRequest;
use App\Services\Api\V2\Administration\ApiKeyAdministration;
use App\Services\Api\V2\Administration\Precondition;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ApiKeyController
{
    public function __construct(private readonly ApiKeyAdministration $keys) {}

    public function index(ListApiKeysRequest $request): JsonResponse
    {
        $page = (int) $request->query('page', 1);
        $perPage = (int) $request->query('per_page', 20);
        $status = $request->query('status');
        $result = $this->keys->list(is_string($status) ? $status : null, $page, $perPage);

        return Envelope::success($result['data'], Envelope::pagination($page, $perPage, $result['total']));
    }

    public function store(StoreApiKeyRequest $request): JsonResponse
    {
        $scopes = $request->input('scopes');
        $rpm = $request->input('abuse_rpm');
        $created = $this->keys->create(
            Precondition::actor($request),
            (string) $request->input('label'),
            is_array($scopes) ? array_map('strval', $scopes) : null,
            $rpm === null ? null : (int) $rpm,
        );

        return Envelope::success($created, status: 201, headers: ['Location' => "/api/v2/api-keys/{$created['id']}"]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->keys->revoke(Precondition::actor($request), $id);

        return Envelope::empty();
    }

    public function usage(int $id): JsonResponse
    {
        return Envelope::success($this->keys->usage($id));
    }
}
