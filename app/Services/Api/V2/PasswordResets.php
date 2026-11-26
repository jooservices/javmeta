<?php

declare(strict_types=1);

namespace App\Services\Api\V2;

use App\Events\PasswordResetRequested;
use App\Http\Api\V2\ProblemException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** Password reset tokens: stored hashed, valid for one hour, usable once. */
final class PasswordResets
{
    private const LIFETIME_MINUTES = 60;

    public function request(string $email): void
    {
        $email = mb_strtolower($email);

        if (! User::query()->where('email', $email)->exists()) {
            return;
        }

        $token = Str::random(40);
        DB::table('password_reset_tokens')->where('email', $email)->delete();
        DB::table('password_reset_tokens')->insert(['email' => $email, 'token' => hash('sha256', $token), 'created_at' => now()]);
        event(new PasswordResetRequested($email, $token));
    }

    public function redeem(string $token, string $password): void
    {
        $row = DB::table('password_reset_tokens')
            ->where('token', hash('sha256', $token))
            ->where('created_at', '>', now()->subMinutes(self::LIFETIME_MINUTES))
            ->first();

        if ($row === null) {
            throw ProblemException::notFound('token_not_found', 'This link is invalid or expired.');
        }

        User::query()->where('email', $row->email)->update(['password' => Hash::make($password)]);
        DB::table('password_reset_tokens')->where('email', $row->email)->delete();
    }
}
