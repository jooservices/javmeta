<?php

declare(strict_types=1);

namespace App\Services\Api\V2\Administration;

use App\Http\Api\V2\ProblemException;
use App\Repositories\Api\V2\AdministrationRepository;
use App\Services\Api\V2\Contracts\AuditLog;
use App\Services\Auth\ApiKeyService;

/** API keys for the administrator: list, create (the secret is shown once), revoke, usage. */
final class ApiKeyAdministration
{
    private const DEFAULT_SCOPES = ['movies', 'performers', 'catalog', 'status'];

    public function __construct(
        private readonly AdministrationRepository $repository,
        private readonly ApiKeyService $keys,
        private readonly AuditLog $audit,
    ) {}

    /** @return array{data: list<array<string, mixed>>, total: int} */
    public function list(?string $status, int $page, int $perPage): array
    {
        $result = $this->repository->apiKeys($status, $page, $perPage);

        return ['data' => array_map($this->view(...), $result['rows']), 'total' => $result['total']];
    }

    /**
     * @param list<string>|null $scopes
     * @return array<string, mixed>
     */
    public function create(string $actor, string $label, ?array $scopes, ?int $abuseRpm): array
    {
        $scopes = array_values(array_unique($scopes ?? self::DEFAULT_SCOPES));
        $created = $this->keys->create($label, $abuseRpm ?? 60, $scopes);
        $id = (int) $created->model->getKey();
        $this->audit->record($actor, 'api_key.create', "api-keys/{$id}", ['label' => $label, 'scopes' => $scopes]);

        return [
            'id' => $id,
            'prefix' => $created->prefix,
            'label' => $label,
            'status' => 'active',
            'abuse_rpm' => $abuseRpm ?? 60,
            'created_at' => Timestamps::iso($created->model->getAttribute('created_at')),
            'scopes' => $scopes,
            'secret' => $created->plaintext,
        ];
    }

    /** Idempotent: revoking a revoked or unknown key still ends in 204. */
    public function revoke(string $actor, int $id): void
    {
        $this->keys->revoke($id);
        $this->audit->record($actor, 'api_key.revoke', "api-keys/{$id}");
    }

    /** @return array<string, mixed> */
    public function usage(int $id): array
    {
        if (! $this->repository->apiKeyExists($id)) {
            throw ProblemException::notFound('api_key_not_found', 'No such API key.');
        }

        return $this->repository->apiKeyUsage($id);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function view(array $row): array
    {
        $scopes = is_string($row['scopes']) ? json_decode($row['scopes'], true) : $row['scopes'];

        return [
            'id' => (int) $row['id'],
            'prefix' => (string) $row['prefix'],
            'label' => (string) $row['label'],
            'status' => (string) $row['status'],
            'abuse_rpm' => (int) $row['abuse_rpm'],
            'created_at' => Timestamps::iso($row['created_at']),
            'revoked_at' => Timestamps::iso($row['revoked_at']),
            'scopes' => is_array($scopes) ? array_values($scopes) : null,
        ];
    }
}
