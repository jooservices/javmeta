<?php

declare(strict_types=1);

namespace App\Data\Auth;

/** The one-time response returned when an API key is created. */
final readonly class CreatedApiKeySnapshot
{
    public function __construct(
        public ApiKeySnapshot $key,
        public string $plaintext,
    ) {}

    /** @return array{id: int, prefix: string, label: string, status: string, revoked_at: ?string, abuse_rpm: int, scopes: list<string>|null, created_at: ?string, plaintext: string} */
    public function toArray(): array
    {
        return [...$this->key->toArray(), 'plaintext' => $this->plaintext];
    }
}
