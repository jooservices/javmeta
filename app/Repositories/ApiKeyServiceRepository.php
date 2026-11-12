<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\ApiKey;

/** Persistence boundary for API key management. */
final class ApiKeyServiceRepository extends BusinessQueryRepository
{
    public function __construct(ApiKey $model)
    {
        parent::__construct($model);
    }

    /** Delete only the matching row; no match is a safe idempotent cleanup. */
    public function deleteByIdAndHash(int $id, string $keyHash): int
    {
        return $this->modelQuery(ApiKey::class)
            ->whereKey($id)
            ->where('key_hash', $keyHash)
            ->delete();
    }

    public function existsByIdAndHash(int $id, string $keyHash): bool
    {
        return $this->modelQuery(ApiKey::class)
            ->whereKey($id)
            ->where('key_hash', $keyHash)
            ->exists();
    }

    /** Revoke only the matching row when deletion is unavailable. */
    public function revokeByIdAndHash(int $id, string $keyHash): int
    {
        return $this->modelQuery(ApiKey::class)
            ->whereKey($id)
            ->where('key_hash', $keyHash)
            ->where('status', ApiKey::STATUS_ACTIVE)
            ->update([
                'status' => ApiKey::STATUS_REVOKED,
                'revoked_at' => now(),
            ]);
    }

    public function existsActiveByIdAndHash(int $id, string $keyHash): bool
    {
        return $this->modelQuery(ApiKey::class)
            ->whereKey($id)
            ->where('key_hash', $keyHash)
            ->where('status', ApiKey::STATUS_ACTIVE)
            ->exists();
    }

    /** Remove all authorization scopes as a durable fallback when status revocation fails. */
    public function clearScopesByIdAndHash(int $id, string $keyHash): int
    {
        return $this->modelQuery(ApiKey::class)
            ->whereKey($id)
            ->where('key_hash', $keyHash)
            ->update(['scopes' => json_encode([])]);
    }

    public function hasActiveScopesByIdAndHash(int $id, string $keyHash): bool
    {
        $apiKey = $this->modelQuery(ApiKey::class)
            ->whereKey($id)
            ->where('key_hash', $keyHash)
            ->where('status', ApiKey::STATUS_ACTIVE)
            ->first(['id', 'scopes']);

        return $apiKey !== null && is_array($apiKey->scopes) && $apiKey->scopes !== [];
    }
}
