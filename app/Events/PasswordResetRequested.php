<?php

declare(strict_types=1);

namespace App\Events;

/** A user asked for a password reset; the token is delivered by whichever listener is configured. */
final readonly class PasswordResetRequested
{
    public function __construct(public string $email, public string $token) {}
}
