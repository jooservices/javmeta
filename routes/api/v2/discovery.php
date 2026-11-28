<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V2\DiscoveryController;
use App\Http\Controllers\Api\V2\SearchController;
use App\Http\Controllers\Api\V2\StatisticsController;
use App\Http\Middleware\ApiV2\VerifyCsrf;
use Illuminate\Support\Facades\Route;

// Included inside Route::prefix('v2')->middleware('api.v2') in routes/api.php.

Route::get('search', [SearchController::class, 'index']);
Route::get('suggestions', [SearchController::class, 'suggestions']);
// Computation only (it changes nothing), so no CSRF token is needed.
Route::post('search/interpretations', [SearchController::class, 'interpretations'])->withoutMiddleware(VerifyCsrf::class);
Route::get('search/photos', [SearchController::class, 'photos']);
Route::get('search/catalog', [SearchController::class, 'catalogue']);

Route::get('updates', [DiscoveryController::class, 'updates']);
Route::get('updates/summary', [DiscoveryController::class, 'summary']);
Route::get('highlights', [DiscoveryController::class, 'highlights']);
Route::get('calendar', [DiscoveryController::class, 'calendar']);

// Statistics are for signed-in users only (a guest does not see the numbers of the system).
Route::prefix('statistics')->middleware('scope:owner')->group(static function (): void {
    Route::get('overview', [StatisticsController::class, 'overview']);
    Route::get('series', [StatisticsController::class, 'series']);
    Route::get('distributions', [StatisticsController::class, 'distributions']);
    Route::get('correlations', [StatisticsController::class, 'correlations']);
    Route::get('data-quality', [StatisticsController::class, 'dataQuality']);
    Route::get('crawl-health', [StatisticsController::class, 'crawlHealth']);
    Route::get('contributions', [StatisticsController::class, 'contributions']);
    Route::get('sources-active', [StatisticsController::class, 'sourcesActive']);
    Route::get('dashboard', [StatisticsController::class, 'dashboard']);
    Route::get('compare', [StatisticsController::class, 'compare']);
});
