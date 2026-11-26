<?php

declare(strict_types=1);

namespace App\Services\Api\V2;

use App\Http\Api\V2\Scope;
use App\Models\User;

/** A user as the v2 API shows it (never carries the password). */
final readonly class UserView
{
    public function __construct(
        public int $id,
        public string $name,
        public string $email,
        public string $role,
        public Scope $scope,
        public ?string $createdAt,
    ) {}

    public static function from(User $user): self
    {
        return new self((int) $user->getKey(), $user->name, $user->email, (string) $user->role, Scope::forUser($user), $user->created_at?->toIso8601String());
    }

    /** @return array{id: int, name: string, email: string, role: string, scope: string, created_at: string|null} */
    public function toArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'email' => $this->email, 'role' => $this->role, 'scope' => $this->scope->value, 'created_at' => $this->createdAt];
    }
}
