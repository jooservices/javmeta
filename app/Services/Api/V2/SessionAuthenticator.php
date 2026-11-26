<?php

declare(strict_types=1);

namespace App\Services\Api\V2;

use App\Http\Api\V2\ProblemException;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

/** Signs users in and out of the web session and tells who is signed in. */
final class SessionAuthenticator
{
    public function signIn(string $email, string $password): UserView
    {
        if (! Auth::guard('web')->attempt(['email' => mb_strtolower($email), 'password' => $password])) {
            throw ProblemException::unauthorized('invalid_credentials', 'Wrong email or password.');
        }

        return $this->current() ?? throw ProblemException::unauthorized();
    }

    public function current(): ?UserView
    {
        $user = Auth::guard('web')->user();

        return $user instanceof User ? UserView::from($user) : null;
    }

    public function signOut(): void
    {
        Auth::guard('web')->logout();
    }
}
