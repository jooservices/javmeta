<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V2\DownloadItemController;
use App\Http\Controllers\Api\V2\DownloadRequestController;
use App\Http\Controllers\Api\V2\LibraryController;
use App\Http\Controllers\Api\V2\MediaJobController;
use App\Http\Controllers\Api\V2\PlaybackSessionController;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

// Included inside Route::prefix('v2')->middleware('api.v2') in routes/api.php.

// Guests may ask for download links too: 8 per minute per client address.
RateLimiter::for('api-v2-download-request', static fn(Request $request): Limit => Limit::perMinute(8)->by((string) $request->ip()));

Route::get('download-requests', [DownloadRequestController::class, 'index']);
Route::post('download-requests', [DownloadRequestController::class, 'store'])->middleware('throttle:api-v2-download-request');
Route::get('download-requests/{id}', [DownloadRequestController::class, 'show'])->whereNumber('id');
Route::delete('download-requests/{id}', [DownloadRequestController::class, 'destroy'])->whereNumber('id');
Route::post('playback-sessions', [PlaybackSessionController::class, 'store']);

Route::middleware('scope:owner')->group(static function (): void {
    Route::get('library', [LibraryController::class, 'summary']);
    Route::get('library/files', [LibraryController::class, 'files']);
    Route::get('library/codes', [LibraryController::class, 'codes']);
    Route::get('media-files', [LibraryController::class, 'mediaFiles']);
    Route::get('download-status', [DownloadItemController::class, 'status']);
    Route::get('download-items', [DownloadItemController::class, 'index']);
    Route::post('download-items', [DownloadItemController::class, 'store']);
    Route::get('download-items/{hash}', [DownloadItemController::class, 'show']);
    Route::patch('download-items/{hash}', [DownloadItemController::class, 'update']);
    Route::delete('download-items/{hash}', [DownloadItemController::class, 'destroy']);
    Route::get('download-items/{hash}/links', [DownloadItemController::class, 'links']);
    Route::get('media-jobs', [MediaJobController::class, 'index']);
    Route::post('media-jobs', [MediaJobController::class, 'store']);
    Route::patch('media-jobs/{id}', [MediaJobController::class, 'update'])->whereNumber('id');
});
