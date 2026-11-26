<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V2\ApiKeyController;
use App\Http\Controllers\Api\V2\NotificationTemplateController;
use App\Http\Controllers\Api\V2\ReportController;
use App\Http\Controllers\Api\V2\SettingsController;
use Illuminate\Support\Facades\Route;

// Included inside Route::prefix('v2')->middleware('api.v2') in routes/api.php.
Route::middleware('scope:admin')->group(static function (): void {
    Route::get('api-keys', [ApiKeyController::class, 'index']);
    Route::post('api-keys', [ApiKeyController::class, 'store']);
    Route::delete('api-keys/{id}', [ApiKeyController::class, 'destroy'])->whereNumber('id');
    Route::get('api-keys/{id}/usage', [ApiKeyController::class, 'usage'])->whereNumber('id');

    Route::get('settings', [SettingsController::class, 'index']);
    Route::get('settings/{group}', [SettingsController::class, 'show']);
    Route::put('settings/{group}', [SettingsController::class, 'update']);

    Route::prefix('notification-templates')->group(static function (): void {
        Route::get('/', [NotificationTemplateController::class, 'index']);
        Route::get('{key}', [NotificationTemplateController::class, 'show']);
        Route::put('{key}', [NotificationTemplateController::class, 'update']);
        Route::get('{key}/revisions', [NotificationTemplateController::class, 'revisions']);
        Route::post('{key}/revisions/{rev}/restorations', [NotificationTemplateController::class, 'restore'])->whereNumber('rev');
        Route::post('{key}/previews', [NotificationTemplateController::class, 'preview']);
        Route::post('{key}/test-deliveries', [NotificationTemplateController::class, 'testDelivery']);
        Route::delete('{key}/overrides', [NotificationTemplateController::class, 'reset']);
    });

    Route::get('reports/schedule', [ReportController::class, 'schedule']);
    Route::put('reports/schedule', [ReportController::class, 'updateSchedule']);
    Route::get('reports/previews', [ReportController::class, 'previews']);
    Route::get('reports/deliveries', [ReportController::class, 'deliveries']);
    Route::post('reports/deliveries', [ReportController::class, 'send']);
    Route::get('reports/{type}', [ReportController::class, 'show']);
});
