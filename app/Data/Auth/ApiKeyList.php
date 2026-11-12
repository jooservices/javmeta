<?php

declare(strict_types=1);

namespace App\Data\Auth;

/** The API keys visible to owner administration. */
final readonly class ApiKeyList
{
    /** @param list<ApiKeySnapshot> $keys */
    public function __construct(
        public array $keys,
        private ?int $perPage = null,
        private ?bool $hasMore = null,
        private ?string $nextCursor = null,
    ) {}

    /** @return list<array{id: int, prefix: string, label: string, status: string, revoked_at: ?string, abuse_rpm: int, scopes: list<string>|null, created_at: ?string}> */
    public function toArray(): array
    {
        return array_map(static fn(ApiKeySnapshot $key): array => $key->toArray(), $this->keys);
    }

    /** @return array{per_page: int, has_more: bool, next_cursor: string|null} */
    public function paginationMeta(): array
    {
        return [
            'per_page' => $this->perPage ?? count($this->keys),
            'has_more' => $this->hasMore ?? false,
            'next_cursor' => $this->nextCursor,
        ];
    }
}
