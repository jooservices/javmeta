<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Data\Auth\ApiKeyList;
use App\Data\Auth\ApiKeyCreateData;
use App\Data\Auth\ApiKeyUsageSnapshot;
use App\Data\Auth\CreatedApiKeySnapshot;
use App\Observability\ObservabilityEmitter;
use App\Repositories\ApiKeyAdministrationRepository;
use App\Repositories\ApiUsageRepository;

/** Owner-facing API key administration use cases. */
final class ApiKeyAdministrationService
{
    public const DEFAULT_PAGE_SIZE = ApiKeyAdministrationRepository::DEFAULT_PAGE_SIZE;

    public const MAX_PAGE_SIZE = ApiKeyAdministrationRepository::MAX_PAGE_SIZE;

    public function __construct(
        private readonly ApiKeyAdministrationRepository $keys,
        private readonly ApiUsageRepository $usage,
        private readonly ApiKeyService $apiKeyService,
        private readonly ObservabilityEmitter $observability,
    ) {}

    public function index(): ApiKeyList
    {
        return $this->keys->allForAdministration();
    }

    public function activePage(?int $cursor = null, int $perPage = self::DEFAULT_PAGE_SIZE): ApiKeyList
    {
        return $this->keys->activePageForAdministration($cursor, $perPage);
    }

    public function create(ApiKeyCreateData $data): CreatedApiKeySnapshot
    {
        $created = $this->apiKeyService->create(
            $data->label,
            $data->abuseRpm,
            array_values(array_unique($data->scopes ?? [])),
        );
        $snapshot = $this->keys->created($created);
        $this->observability->emitOps('admin_api_key', [
            'action' => 'create',
            'api_key_id' => $snapshot->key->id,
        ]);

        return $snapshot;
    }

    public function usage(int $id): ApiKeyUsageSnapshot
    {
        $this->keys->assertExists($id);

        return $this->usage->summaryForKey($id);
    }

    public function revoke(int $id): void
    {
        $this->apiKeyService->revoke($id);
        $this->observability->emitOps('admin_api_key', [
            'action' => 'revoke',
            'api_key_id' => $id,
        ]);
    }
}
