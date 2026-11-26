<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V2;

use App\Http\Api\V2\Envelope;
use App\Http\Requests\Api\V2\SignInRequest;
use App\Services\Api\V2\SessionAuthenticator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use stdClass;

final class SessionController
{
    public function __construct(private readonly SessionAuthenticator $sessions) {}

    public function store(SignInRequest $request): JsonResponse
    {
        $user = $this->sessions->signIn((string) $request->input('email'), (string) $request->input('password'));
        $request->session()->regenerate();

        return Envelope::success(['user' => $user->toArray(), 'scope' => $user->scope->value, 'csrf' => $request->session()->token()], status: 201);
    }

    public function current(Request $request): JsonResponse
    {
        $user = $this->sessions->current();
        $loginRequired = (bool) config('javmeta.ui_login_required', true);

        if ($user === null) {
            return Envelope::success(['scope' => 'guest', 'user' => null, 'login_required' => $loginRequired]);
        }

        return Envelope::success([
            'scope' => $user->scope->value,
            'user' => $user->toArray(),
            'csrf' => $request->session()->token(),
            'preferences' => new stdClass(),
            'login_required' => $loginRequired,
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $this->sessions->signOut();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Envelope::empty();
    }
}
