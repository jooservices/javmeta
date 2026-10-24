<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\ApiKey;
use App\Support\Auth\CreatedApiKey;
use Illuminate\Support\Str;

final class ApiKeyService
{
    /** @param list<string>|null $scopes null grants every scope */
    public function create(string $label, int $abuseRpm = 60, ?array $scopes = null): CreatedApiKey
    {
        $plaintext = 'jvm_' . Str::random(48);
        $prefix = substr($plaintext, 0, 12);

        $model = ApiKey::query()->create([
            'prefix' => $prefix,
            'key_hash' => $this->hashPlaintext($plaintext),
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
        ApiKey::query()
            ->whereKey($id)
            ->update([
                'status' => ApiKey::STATUS_REVOKED,
                'revoked_at' => now(),
            ]);
    }

    public function findActiveByPlaintext(string $plain): ?ApiKey
    {
        if ($plain === '' || ! str_starts_with($plain, 'jvm_')) {
            return null;
        }

        return ApiKey::query()
            ->where('key_hash', $this->hashPlaintext($plain))
            ->where('status', ApiKey::STATUS_ACTIVE)
            ->first();
    }

    private function hashPlaintext(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }
}
