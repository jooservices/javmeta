<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\CreateApiKeyRequest;
use App\Services\Auth\ApiKeyAdministrationService;
use Illuminate\Http\JsonResponse;
use JOOservices\LaravelController\Http\Controllers\BaseApiController;

final class ApiKeyController extends BaseApiController
{
    public function index(ApiKeyAdministrationService $apiKeys): JsonResponse
    {
        return $this->respondWithData($apiKeys->index()->toArray());
    }

    public function store(CreateApiKeyRequest $request, ApiKeyAdministrationService $apiKeys): JsonResponse
    {
        return $this->created($apiKeys->create($request->createData())->toArray());
    }

    public function usage(int $id, ApiKeyAdministrationService $apiKeys): JsonResponse
    {
        return $this->respondWithData($apiKeys->usage($id)->toArray());
    }

    public function destroy(int $id, ApiKeyAdministrationService $apiKeys): JsonResponse
    {
        $apiKeys->revoke($id);

        return $this->respondNoContent();
    }
}
