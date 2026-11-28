<?php

declare(strict_types=1);

namespace App\Repositories\Api\V2;

use App\Models\User;

/** Persistence of the application users for the v2 account and user-management operations. */
final class UserRepository
{
    public function find(int $id): ?User
    {
        return User::query()->find($id);
    }

    public function emailExists(string $email): bool
    {
        return User::query()->where('email', $email)->exists();
    }

    /** @return list<User> */
    public function all(): array
    {
        return User::query()->orderBy('id')->get()->all();
    }

    public function create(string $name, string $email, string $password, string $role): User
    {
        return User::query()->create(['name' => $name, 'email' => $email, 'password' => $password, 'role' => $role]);
    }

    public function delete(int $id): void
    {
        User::query()->whereKey($id)->delete();
    }

    public function setPasswordByEmail(string $email, string $hash): void
    {
        User::query()->where('email', $email)->update(['password' => $hash]);
    }
}
