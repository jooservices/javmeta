<?php

declare(strict_types=1);

namespace App\Services\Api\V2;

use App\Events\PasswordResetRequested;
use App\Http\Api\V2\ProblemException;
use App\Repositories\Api\V2\PasswordResetTokenRepository;
use App\Repositories\Api\V2\UserRepository;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** Password reset tokens: stored hashed, valid for one hour, usable once. */
final class PasswordResets
{
    private const LIFETIME_MINUTES = 60;

    public function __construct(private readonly UserRepository $users, private readonly PasswordResetTokenRepository $tokens) {}

    public function request(string $email): void
    {
        $email = mb_strtolower($email);

        if (! $this->users->emailExists($email)) {
            return;
        }

        $token = Str::random(40);
        $this->tokens->replace($email, hash('sha256', $token));
        event(new PasswordResetRequested($email, $token));
    }

    public function redeem(string $token, string $password): void
    {
        $email = $this->tokens->emailOfValid(hash('sha256', $token), self::LIFETIME_MINUTES);

        if ($email === null) {
            throw ProblemException::notFound('token_not_found', 'This link is invalid or expired.');
        }

        $this->users->setPasswordByEmail($email, Hash::make($password));
        $this->tokens->forget($email);
    }
}
