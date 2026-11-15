<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Repositories\ApiKeyServiceRepository;
use App\Models\ApiKey;
use App\Support\Auth\CreatedApiKey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

final class ApiKeyService
{
    private const ACTIVE_LOOKUP_CACHE_SECONDS = 5;

    private const ACTIVE_LOOKUP_CACHE_PREFIX = 'javmeta:api-key:active:';

    private const REVOKED_LOOKUP_CACHE_MARKER = 'revoked';

    /** @param list<string> $scopes An empty list grants no API scopes. */
    public function create(string $label, int $abuseRpm = 60, array $scopes = []): CreatedApiKey
    {
        $plaintext = 'jvm_' . Str::random(48);
        return $this->persist($plaintext, $label, $abuseRpm, $scopes);
    }

    public function createSmokeCheckKey(): CreatedApiKey
    {
        return $this->persist('jvm_smoke_' . Str::random(40), 'JavMeta deployment smoke check', 60, ['performers', 'mcp']);
    }

    /** @param list<string> $scopes */
    private function persist(string $plaintext, string $label, int $abuseRpm, array $scopes): CreatedApiKey
    {
        $keyHash = $this->hashPlaintext($plaintext);
        $prefix = substr($plaintext, 0, 12);

        $model = app(ApiKeyServiceRepository::class)->modelQuery(ApiKey::class)->create([
            'prefix' => $prefix,
            'key_hash' => $keyHash,
            'label' => $label,
            'status' => ApiKey::STATUS_ACTIVE,
            'revoked_at' => null,
            'abuse_rpm' => $abuseRpm,
            'scopes' => $scopes,
            'created_at' => now(),
        ]);

        return new CreatedApiKey($plaintext, $prefix, $model);
    }

    public function revoke(int $id): void
    {
        $keyHash = app(ApiKeyServiceRepository::class)->modelQuery(ApiKey::class)->whereKey($id)->value('key_hash');
        app(ApiKeyServiceRepository::class)->modelQuery(ApiKey::class)
            ->whereKey($id)
            ->update([
                'status' => ApiKey::STATUS_REVOKED,
                'revoked_at' => now(),
            ]);

        if (is_string($keyHash)) {
            try {
                $this->clearActiveLookupCache($keyHash);
            } catch (Throwable) {
                // The database update remains authoritative if cache invalidation fails.
            }
        }
    }

    public function deleteCreated(CreatedApiKey $createdKey): void
    {
        $keyHash = $createdKey->model->getAttribute('key_hash');
        if (! is_string($keyHash)) {
            throw new UnexpectedValueException('The created API key is missing its persistence hash.');
        }

        $repository = app(ApiKeyServiceRepository::class);
        $id = (int) $createdKey->model->getKey();
        try {
            $deletedRows = $repository->deleteByIdAndHash($id, $keyHash);
            if ($deletedRows === 0 && $repository->existsByIdAndHash($id, $keyHash)) {
                throw new RuntimeException('The created API key remains after deletion.');
            }
        } catch (Throwable $deleteFailure) {
            $revocationConfirmed = false;
            try {
                $repository->revokeByIdAndHash($id, $keyHash);
                if ($repository->existsActiveByIdAndHash($id, $keyHash)) {
                    throw new RuntimeException('The created API key remains active after revocation.');
                }
                $revocationConfirmed = true;
            } catch (Throwable) {
                try {
                    $repository->clearScopesByIdAndHash($id, $keyHash);
                    if ($repository->hasActiveScopesByIdAndHash($id, $keyHash)) {
                        throw new RuntimeException('The created API key remains authorized after scope removal.');
                    }
                    $revocationConfirmed = true;
                } catch (Throwable) {
                    // Preserve the deletion failure for the caller's safe cleanup result.
                }
            }

            try {
                if ($revocationConfirmed) {
                    $this->clearActiveLookupCache($keyHash);
                } else {
                    Cache::forever(
                        $this->activeLookupCacheKey($keyHash),
                        self::REVOKED_LOOKUP_CACHE_MARKER,
                    );
                }
            } catch (Throwable) {
                try {
                    Cache::forever(
                        $this->activeLookupCacheKey($keyHash),
                        self::REVOKED_LOOKUP_CACHE_MARKER,
                    );
                } catch (Throwable) {
                    // Preserve the deletion failure if cache invalidation also fails.
                }
            }

            throw $deleteFailure;
        }

        $this->clearActiveLookupCache($keyHash);
    }

    public function findActiveByPlaintext(string $plain): ?ApiKey
    {
        if ($plain === '' || ! str_starts_with($plain, 'jvm_')) {
            return null;
        }

        $keyHash = $this->hashPlaintext($plain);
        if (str_starts_with($plain, 'jvm_smoke_')) {
            // A smoke key may stay active when cleanup can only remove its scopes; never trust a cached row here.
            return $this->findActiveSmokeKey($keyHash);
        }

        $cacheKey = $this->activeLookupCacheKey($keyHash);
        try {
            $cached = Cache::get($cacheKey);
        } catch (Throwable) {
            $cached = null;
        }
        if ($cached === self::REVOKED_LOOKUP_CACHE_MARKER) {
            return null;
        }
        if (
            $cached instanceof ApiKey
            && $cached->status === ApiKey::STATUS_ACTIVE
            && hash_equals($cached->key_hash, $keyHash)
        ) {
            return $cached;
        }
        if ($cached !== null) {
            try {
                Cache::forget($cacheKey);
            } catch (Throwable) {
                // The database lookup below remains authoritative.
            }
        }

        $apiKey = $this->findActiveKey($keyHash);
        if ($apiKey !== null) {
            try {
                Cache::put($cacheKey, $apiKey, self::ACTIVE_LOOKUP_CACHE_SECONDS);
            } catch (Throwable) {
                // Authentication remains available through PostgreSQL if Redis is unavailable.
            }
        }

        return $apiKey;
    }

    private function findActiveKey(string $keyHash): ?ApiKey
    {
        return app(ApiKeyServiceRepository::class)->modelQuery(ApiKey::class)
            ->where('key_hash', $keyHash)
            ->where('status', ApiKey::STATUS_ACTIVE)
            ->first();
    }

    private function findActiveSmokeKey(string $keyHash): ?ApiKey
    {
        $apiKey = $this->findActiveKey($keyHash);

        return $apiKey !== null && is_array($apiKey->scopes) && $apiKey->scopes !== [] ? $apiKey : null;
    }

    private function activeLookupCacheKey(string $keyHash): string
    {
        return self::ACTIVE_LOOKUP_CACHE_PREFIX . hash('sha256', $keyHash);
    }

    private function clearActiveLookupCache(string $keyHash): void
    {
        Cache::forget($this->activeLookupCacheKey($keyHash));
    }

    private function hashPlaintext(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }
}
