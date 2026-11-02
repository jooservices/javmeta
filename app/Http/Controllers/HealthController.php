<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Health\StatusHealthCheck;
use Illuminate\Http\JsonResponse;

final class HealthController
{
    public function __invoke(StatusHealthCheck $health): JsonResponse
    {
        $payload = $health->status();
        if (! (bool) config('javmeta.ui_login_required', true)) {
            $payload = array_intersect_key($payload, array_flip(['status', 'checked_at']));
        }

        $status = match ($payload['status']) {
            'down' => 503,
            'degraded' => 200,
            default => 200,
        };

        return response()->json($payload, $status);
    }
}
