<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V2\AlertController;
use App\Http\Controllers\Api\V2\CrawlController;
use App\Http\Controllers\Api\V2\EventController;
use App\Http\Controllers\Api\V2\NodeController;
use App\Http\Controllers\Api\V2\OperationsController;
use App\Http\Controllers\Api\V2\SourceController;
use App\Http\Controllers\Api\V2\StatusController;
use Illuminate\Support\Facades\Route;

// Included inside Route::prefix('v2')->middleware('api.v2') in routes/api.php.

// Public numbers of the top bar.
Route::get('status/summary', [StatusController::class, 'summary']);

// Command Center: administrators only.
Route::middleware('scope:admin')->group(static function (): void {
    Route::get('operations/overview', [OperationsController::class, 'overview']);
    Route::get('operations/performance', [OperationsController::class, 'performance']);
    Route::get('infrastructure', [OperationsController::class, 'infrastructure']);

    Route::get('sources', [SourceController::class, 'index']);
    Route::get('sources/{slug}', [SourceController::class, 'show']);
    Route::patch('sources/{slug}', [SourceController::class, 'update']);

    Route::get('crawl-runs', [CrawlController::class, 'runs']);
    Route::get('crawl-runs/{id}', [CrawlController::class, 'run'])->whereNumber('id');
    Route::get('crawl-runs/{id}/attempts', [CrawlController::class, 'attempts'])->whereNumber('id');
    Route::get('crawl-queue', [CrawlController::class, 'queue']);
    Route::get('crawl-errors', [CrawlController::class, 'errors']);

    Route::get('events', [EventController::class, 'index']);
    Route::get('events/stream', [EventController::class, 'stream']);

    Route::get('nodes', [NodeController::class, 'index']);
    Route::get('nodes/{id}', [NodeController::class, 'show']);
    Route::get('node-enrollments', [NodeController::class, 'enrollments']);
    Route::post('node-enrollments', [NodeController::class, 'enroll']);
    Route::delete('node-enrollments/{id}', [NodeController::class, 'revoke'])->whereNumber('id');

    Route::get('alerts', [AlertController::class, 'alerts']);
    Route::get('review-flags', [AlertController::class, 'flags']);
    Route::patch('review-flags/{id}', [AlertController::class, 'resolveFlag'])->whereNumber('id');
});
