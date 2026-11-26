<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V2\MeController;
use App\Http\Controllers\Api\V2\MeFeedController;
use App\Http\Controllers\Api\V2\MeFollowController;
use App\Http\Controllers\Api\V2\MeHistoryController;
use App\Http\Controllers\Api\V2\MeMarkController;
use App\Http\Controllers\Api\V2\MeSavedSearchController;
use Illuminate\Support\Facades\Route;

// Included inside Route::prefix('v2')->middleware('api.v2') in routes/api.php.

Route::middleware('scope:owner')->prefix('me')->group(static function (): void {
    Route::get('/', [MeController::class, 'show']);
    Route::patch('/', [MeController::class, 'update']);
    Route::patch('preferences', [MeController::class, 'preferences']);

    Route::get('marks', [MeMarkController::class, 'index']);
    Route::get('marks/movies', [MeMarkController::class, 'movies']);
    Route::get('marks/{kind}/{target}/{ref}', [MeMarkController::class, 'show']);
    Route::put('marks/{kind}/{target}/{ref}', [MeMarkController::class, 'put']);
    Route::delete('marks/{kind}/{target}/{ref}', [MeMarkController::class, 'destroy']);

    Route::get('follows', [MeFollowController::class, 'index']);
    Route::get('follows/{kind}/{ref}', [MeFollowController::class, 'show']);
    Route::put('follows/{kind}/{ref}', [MeFollowController::class, 'put']);
    Route::delete('follows/{kind}/{ref}', [MeFollowController::class, 'destroy']);

    Route::get('feed', [MeFeedController::class, 'index']);
    Route::patch('feed', [MeFeedController::class, 'seen']);

    Route::get('saved-searches', [MeSavedSearchController::class, 'index']);
    Route::post('saved-searches', [MeSavedSearchController::class, 'store']);
    Route::patch('saved-searches/{id}', [MeSavedSearchController::class, 'update'])->whereNumber('id');
    Route::delete('saved-searches/{id}', [MeSavedSearchController::class, 'destroy'])->whereNumber('id');

    Route::get('history', [MeHistoryController::class, 'index']);
    Route::post('history', [MeHistoryController::class, 'store']);
    Route::delete('history', [MeHistoryController::class, 'destroy']);
});
