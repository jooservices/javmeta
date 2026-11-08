<?php

declare(strict_types=1);

namespace App\Data\Auth;

/** Validated values needed to create an owner-managed API key. */
final readonly class ApiKeyCreateData
{
    /**
     * @param list<string>|null $scopes
     */
    public function __construct(
        public string $label,
        public int $abuseRpm,
        public ?array $scopes,
    ) {}
}
