<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ui;

use App\Http\Controllers\Admin\ApiKeyController;
use App\Http\Controllers\Admin\ReportController;
use Illuminate\Http\JsonResponse;

/**
 * What the owner's admin page needs (keys and reports) behind the signed-in session, so the browser never holds the
 * owner admin token. Each call runs the same controller as the token-protected /api/v1/admin route.
 */
final class UiAdminController
{
    public function keys(): JsonResponse
    {
        return app()->call([app(ApiKeyController::class), 'index']);
    }

    public function createKey(): JsonResponse
    {
        return app()->call([app(ApiKeyController::class), 'store']);
    }

    public function usage(int $id): JsonResponse
    {
        return app()->call([app(ApiKeyController::class), 'usage'], ['id' => $id]);
    }

    public function revoke(int $id): \Symfony\Component\HttpFoundation\Response
    {
        return app()->call([app(ApiKeyController::class), 'destroy'], ['id' => $id]);
    }

    public function report(string $type): JsonResponse
    {
        return app()->call([app(ReportController::class), 'show'], ['type' => $type]);
    }
}
