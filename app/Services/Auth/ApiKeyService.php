<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\ApiKey;
use App\Support\Auth\CreatedApiKey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

final class ApiKeyService
{
    private const ACTIVE_LOOKUP_CACHE_SECONDS = 5;

    private const ACTIVE_LOOKUP_CACHE_PREFIX = 'javmeta:api-key:active:';

    /** @param list<string>|null $scopes null grants every scope */
    public function create(string $label, int $abuseRpm = 60, ?array $scopes = null): CreatedApiKey
    {
        $plaintext = 'jvm_' . Str::random(48);
        $keyHash = $this->hashPlaintext($plaintext);
        $prefix = substr($plaintext, 0, 12);

        $model = ApiKey::query()->create([
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
        $keyHash = ApiKey::query()->whereKey($id)->value('key_hash');
        ApiKey::query()
            ->whereKey($id)
            ->update([
                'status' => ApiKey::STATUS_REVOKED,
                'revoked_at' => now(),
            ]);

        if (is_string($keyHash)) {
            try {
                Cache::forget($this->activeLookupCacheKey($keyHash));
            } catch (Throwable) {
                // The database update remains authoritative if cache invalidation fails.
            }
        }
    }

    public function findActiveByPlaintext(string $plain): ?ApiKey
    {
        if ($plain === '' || ! str_starts_with($plain, 'jvm_')) {
            return null;
        }

        $keyHash = $this->hashPlaintext($plain);
        $cacheKey = $this->activeLookupCacheKey($keyHash);
        try {
            $cached = Cache::get($cacheKey);
        } catch (Throwable) {
            $cached = null;
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

        $apiKey = ApiKey::query()
            ->where('key_hash', $keyHash)
            ->where('status', ApiKey::STATUS_ACTIVE)
            ->first();
        if ($apiKey !== null) {
            try {
                Cache::put($cacheKey, $apiKey, self::ACTIVE_LOOKUP_CACHE_SECONDS);
            } catch (Throwable) {
                // Authentication remains available through PostgreSQL if Redis is unavailable.
            }
        }

        return $apiKey;
    }

    private function activeLookupCacheKey(string $keyHash): string
    {
        return self::ACTIVE_LOOKUP_CACHE_PREFIX . hash('sha256', $keyHash);
    }

    private function hashPlaintext(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }
}
