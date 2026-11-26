<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V2;

use App\Http\Api\V2\Envelope;
use App\Http\Requests\Api\V2\NewPasswordRequest;
use App\Http\Requests\Api\V2\PasswordResetRequest;
use App\Services\Api\V2\PasswordResets;
use Illuminate\Http\JsonResponse;

final class PasswordResetController
{
    public function __construct(private readonly PasswordResets $resets) {}

    public function store(PasswordResetRequest $request): JsonResponse
    {
        $this->resets->request((string) $request->input('email'));

        return Envelope::success(['accepted' => true], status: 202);
    }

    public function update(NewPasswordRequest $request, string $token): JsonResponse
    {
        $this->resets->redeem($token, (string) $request->input('password'));

        return Envelope::empty();
    }
}
