<?php

declare(strict_types=1);

namespace App\Data\Auth;

/** A safe API key representation for owner administration responses. */
final readonly class ApiKeySnapshot
{
    /**
     * @param list<string>|null $scopes
     */
    public function __construct(
        public int $id,
        public string $prefix,
        public string $label,
        public string $status,
        public ?string $revokedAt,
        public int $abuseRpm,
        public ?array $scopes,
        public ?string $createdAt,
    ) {}

    /** @return array{id: int, prefix: string, label: string, status: string, revoked_at: ?string, abuse_rpm: int, scopes: list<string>|null, created_at: ?string} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'prefix' => $this->prefix,
            'label' => $this->label,
            'status' => $this->status,
            'revoked_at' => $this->revokedAt,
            'abuse_rpm' => $this->abuseRpm,
            'scopes' => $this->scopes,
            'created_at' => $this->createdAt,
        ];
    }
}
