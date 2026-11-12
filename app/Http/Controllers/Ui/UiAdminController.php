<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ui;

use App\Http\Controllers\Admin\ApiKeyController;
use App\Http\Controllers\Admin\ReportController;
use App\Services\Auth\ApiKeyAdministrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use JOOservices\LaravelController\Http\Controllers\BaseApiController;

/**
 * Owner admin UI JSON endpoints behind the signed-in session, so the browser never holds the owner admin token.
 * The key list is paginated here; mutations and reports use the token-protected admin controllers.
 */
final class UiAdminController extends BaseApiController
{
    public function keys(Request $request, ApiKeyAdministrationService $apiKeys): JsonResponse
    {
        $validated = $request->validate([
            'cursor' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:' . ApiKeyAdministrationService::MAX_PAGE_SIZE],
        ]);
        $page = $apiKeys->activePage(
            isset($validated['cursor']) ? (int) $validated['cursor'] : null,
            (int) ($validated['per_page'] ?? ApiKeyAdministrationService::DEFAULT_PAGE_SIZE),
        );

        return $this->respondWithData(
            data: $page->toArray(),
            meta: ['pagination' => $page->paginationMeta()],
        );
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
