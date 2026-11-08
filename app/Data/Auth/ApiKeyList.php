<?php

declare(strict_types=1);

namespace App\Data\Auth;

/** The API keys visible to owner administration. */
final readonly class ApiKeyList
{
    /** @param list<ApiKeySnapshot> $keys */
    public function __construct(public array $keys) {}

    /** @return list<array{id: int, prefix: string, label: string, status: string, revoked_at: ?string, abuse_rpm: int, scopes: list<string>|null, created_at: ?string}> */
    public function toArray(): array
    {
        return array_map(static fn(ApiKeySnapshot $key): array => $key->toArray(), $this->keys);
    }
}
