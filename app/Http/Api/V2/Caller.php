<?php

declare(strict_types=1);

namespace App\Http\Api\V2;

use Illuminate\Support\Facades\Auth;

/** The signed-in user of the current request (the scope middleware has already refused everybody else). */
final class Caller
{
    public static function userId(): int
    {
        return (int) (Auth::guard('web')->id() ?? throw ProblemException::unauthorized());
    }
}
