<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V2\MovieController;
use App\Http\Controllers\Api\V2\PerformerController;
use Illuminate\Support\Facades\Route;

// Included inside Route::prefix('v2')->middleware('api.v2') in routes/api.php.

Route::get('movies', [MovieController::class, 'index']);
Route::get('movies/facets', [MovieController::class, 'facets']);
Route::get('performers', [PerformerController::class, 'index']);
Route::get('performers/facets', [PerformerController::class, 'facets']);
