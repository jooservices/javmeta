<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Operations\DashboardController;
use App\Http\Controllers\Operations\LoginController;
use App\Http\Controllers\Ui\UiAdminController;
use App\Http\Controllers\Ui\UiApiController;
use App\Http\Controllers\Ui\UiMarksController;
use App\Http\Controllers\Ui\UiShellController;
use App\Http\Middleware\EnsureSuperAdministrator;

Route::get('/', static fn() => redirect()->route('ui.index'));

Route::get('/login', [LoginController::class, 'show'])->name('login');
Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:6,1');
Route::post('/logout', [LoginController::class, 'destroy'])->middleware('auth')->name('logout');

Route::middleware(['auth', EnsureSuperAdministrator::class])->group(static function (): void {
    Route::get('/ops', [DashboardController::class, 'index'])->name('operations.index');
    Route::get('/ops/data', [DashboardController::class, 'data'])->name('operations.data');

    Route::get('/app', UiShellController::class)->name('ui.index');
    Route::get('/ui-admin/keys', [UiAdminController::class, 'keys']);
    Route::post('/ui-admin/keys', [UiAdminController::class, 'createKey']);
    Route::get('/ui-admin/keys/{id}/usage', [UiAdminController::class, 'usage'])->whereNumber('id');
    Route::delete('/ui-admin/keys/{id}', [UiAdminController::class, 'revoke'])->whereNumber('id');
    Route::get('/ui-admin/report/{type}', [UiAdminController::class, 'report']);
    Route::get('/ui-me/marks', [UiMarksController::class, 'index']);
    Route::post('/ui-me/marks', [UiMarksController::class, 'toggle']);
    Route::get('/ui-me/movies/{kind}', [UiMarksController::class, 'movies']);
    Route::get('/ui-me/feed', [UiMarksController::class, 'feed']);
    Route::post('/ui-me/feed/seen', [UiMarksController::class, 'feedSeen']);
    Route::get('/ui-api/{path}', UiApiController::class)->where('path', '.*')->name('ui.api');
});
