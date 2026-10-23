<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Operations\DashboardController;
use App\Http\Controllers\Operations\LoginController;
use App\Http\Middleware\EnsureSuperAdministrator;

Route::get('/', static fn() => redirect()->route('operations.index'));

Route::get('/login', [LoginController::class, 'show'])->name('login');
Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:6,1');
Route::post('/logout', [LoginController::class, 'destroy'])->middleware('auth')->name('logout');

Route::middleware(['auth', EnsureSuperAdministrator::class])->group(static function (): void {
    Route::get('/ops', [DashboardController::class, 'index'])->name('operations.index');
    Route::get('/ops/data', [DashboardController::class, 'data'])->name('operations.data');
});
