<?php

declare(strict_types=1);

namespace App\Http\Api\V2;

use App\Models\User;

/** What the caller of the v2 API may do: nobody signed in, a signed-in user, or an administrator. */
enum Scope: string
{
    case Guest = 'guest';
    case Owner = 'owner';
    case Admin = 'admin';

    public static function forUser(?User $user): self
    {
        if (! $user instanceof User) {
            return self::Guest;
        }

        return in_array($user->role, ['admin', 'administrator', 'super_administrator'], true) ? self::Admin : self::Owner;
    }

    public function rank(): int
    {
        return match ($this) {
            self::Guest => 0,
            self::Owner => 1,
            self::Admin => 2,
        };
    }

    public function allows(self $needed): bool
    {
        return $this->rank() >= $needed->rank();
    }
}
