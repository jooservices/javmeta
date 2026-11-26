<?php

declare(strict_types=1);

namespace App\Repositories\Api\V2;

use Illuminate\Support\Facades\DB;

/** The hashed password reset tokens. */
final class PasswordResetTokenRepository
{
    public function replace(string $email, string $tokenHash): void
    {
        DB::table('password_reset_tokens')->where('email', $email)->delete();
        DB::table('password_reset_tokens')->insert(['email' => $email, 'token' => $tokenHash, 'created_at' => now()]);
    }

    /** The email owning a token that is not older than the given number of minutes, or null. */
    public function emailOfValid(string $tokenHash, int $minutes): ?string
    {
        $row = DB::table('password_reset_tokens')->where('token', $tokenHash)->where('created_at', '>', now()->subMinutes($minutes))->first();

        return $row === null ? null : (string) $row->email;
    }

    public function forget(string $email): void
    {
        DB::table('password_reset_tokens')->where('email', $email)->delete();
    }
}
