<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\ApiKeyController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\SourceLoginCookieController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\HealthLiveController;
use App\Http\Controllers\McpHttpController;
use App\Http\Controllers\MetaGenreController;
use App\Http\Controllers\MovieBulkController;
use App\Http\Controllers\MovieLookupController;
use App\Http\Controllers\MovieSearchController;
use App\Http\Controllers\PerformerController;
use App\Http\Middleware\AuthenticateApiKey;
use App\Http\Middleware\LogApiUsage;
use App\Http\Middleware\OwnerAdminGuard;
use App\Http\Middleware\TraceHttpRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;
use JOOservices\LaravelController\Traits\HasApiResponses;

Route::get('/health', HealthController::class);
Route::get('/health/live', HealthLiveController::class);

Route::middleware(TraceHttpRequest::class)->group(function (): void {
    Route::prefix('v1')->group(function (): void {
        Route::middleware([OwnerAdminGuard::class, 'requires:postgres'])->prefix('admin')->group(function (): void {
            Route::get('keys', [ApiKeyController::class, 'index']);
            Route::post('keys', [ApiKeyController::class, 'store']);
            Route::delete('keys/{id}', [ApiKeyController::class, 'destroy'])->whereNumber('id');
            Route::get('report/{type}', [ReportController::class, 'show']);
            Route::put('sources/{slug}/login-cookies', [SourceLoginCookieController::class, 'update']);
            Route::delete('sources/{slug}/login-cookies', [SourceLoginCookieController::class, 'destroy']);
        });

        Route::middleware([AuthenticateApiKey::class, 'requires:postgres', LogApiUsage::class])->get('auth/verify', static function (): JsonResponse {
            return (new class {
                use HasApiResponses;
            })->respondWithData(['status' => 'ok']);
        });

        Route::middleware([AuthenticateApiKey::class, 'requires:postgres', LogApiUsage::class])->group(function (): void {
            Route::get('performers', [PerformerController::class, 'index']);
            Route::get('performers/{id}', [PerformerController::class, 'show']);
            Route::get('movies/{code}', [MovieLookupController::class, 'show']);
            Route::post('movies:bulk', [MovieBulkController::class, 'store']);
            Route::get('meta/genres', [MetaGenreController::class, 'index']);
        });

        Route::middleware([AuthenticateApiKey::class, LogApiUsage::class])->group(function (): void {
            Route::match(['get', 'post'], 'mcp', McpHttpController::class);
        });

        Route::get('movies', [MovieSearchController::class, 'index'])
            ->middleware([AuthenticateApiKey::class, 'requires:postgres', LogApiUsage::class]);
    });
});
