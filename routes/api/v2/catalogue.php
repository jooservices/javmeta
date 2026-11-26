<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V2\CatalogController;
use App\Http\Controllers\Api\V2\MovieController;
use App\Http\Controllers\Api\V2\MovieDetailController;
use App\Http\Controllers\Api\V2\PerformerController;
use App\Http\Controllers\Api\V2\PerformerDetailController;
use App\Http\Middleware\ApiV2\VerifyCsrf;
use Illuminate\Support\Facades\Route;

// Included inside Route::prefix('v2')->middleware('api.v2') in routes/api.php.

Route::get('movies', [MovieController::class, 'index']);
Route::get('movies/facets', [MovieController::class, 'facets']);
Route::get('performers', [PerformerController::class, 'index']);
Route::get('performers/facets', [PerformerController::class, 'facets']);

// Movies: fixed paths first, then the ones that carry a code.
Route::get('movies/facets/values', [MovieDetailController::class, 'facetValues']);
Route::post('movies/lookups', [MovieDetailController::class, 'lookups'])->withoutMiddleware(VerifyCsrf::class);
Route::get('movies/views', [MovieDetailController::class, 'views']);
Route::get('movies/{code}', [MovieDetailController::class, 'show']);
Route::post('movies/{code}/views', [MovieDetailController::class, 'recordView'])->middleware('throttle:60,1');
Route::get('movies/{code}/codes', [MovieDetailController::class, 'codes']);
Route::get('movies/{code}/sources', [MovieDetailController::class, 'sources']);
Route::get('movies/{code}/media', [MovieDetailController::class, 'media']);
Route::get('movies/{code}/downloads', [MovieDetailController::class, 'downloads']);
Route::get('movies/{code}/related', [MovieDetailController::class, 'related']);

// Performers.
Route::get('performers/movie-facets', [PerformerDetailController::class, 'movieFacets']);
Route::get('performers/facets/values', [PerformerDetailController::class, 'facetValues']);
Route::post('performers/lookups', [PerformerDetailController::class, 'lookups'])->withoutMiddleware(VerifyCsrf::class);
Route::get('performers/{id}', [PerformerDetailController::class, 'show']);
Route::get('performers/{id}/sources', [PerformerDetailController::class, 'sources']);
Route::get('performers/{id}/photos', [PerformerDetailController::class, 'photos']);
Route::get('performers/{id}/co-performers', [PerformerDetailController::class, 'coPerformers']);
Route::get('performers/{id}/statistics', [PerformerDetailController::class, 'statistics']);
Route::get('performers/{id}/movies', [PerformerDetailController::class, 'movies']);

// Catalogues and the photo collection.
Route::get('catalogs', [CatalogController::class, 'kinds']);
Route::get('catalogs/{kind}', [CatalogController::class, 'entries']);
Route::get('catalogs/{kind}/index', [CatalogController::class, 'index']);
Route::get('catalogs/{kind}/{slug}', [CatalogController::class, 'entry']);
Route::get('photos', [CatalogController::class, 'photos']);
