<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Operations\DashboardController;
use App\Http\Controllers\Operations\LoginController;
use App\Http\Controllers\Ui\UiAdminController;
use App\Http\Controllers\Ui\UiApiController;
use App\Http\Controllers\FileController;
use App\Http\Controllers\Ui\UiDownloadsController;
use App\Http\Controllers\Ui\UiLibraryController;
use App\Http\Controllers\Ui\UiMarksController;
use App\Http\Controllers\Ui\UiSettingsController;
use App\Http\Controllers\Ui\UiShellController;
use App\Http\Middleware\EnsureSuperAdministrator;

Route::get('/', static fn() => redirect()->route('ui.index'));

Route::get('/files/download', [FileController::class, 'download'])->middleware('signed')->name('files.download');

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
    Route::get('/ui-me/downloads/status', [UiDownloadsController::class, 'status']);
    Route::get('/ui-me/downloads', [UiDownloadsController::class, 'index']);
    Route::post('/ui-me/downloads', [UiDownloadsController::class, 'store']);
    Route::post('/ui-me/downloads/{hash}/pause', [UiDownloadsController::class, 'pause'])->where('hash', '[A-Za-z0-9]+');
    Route::post('/ui-me/downloads/{hash}/resume', [UiDownloadsController::class, 'resume'])->where('hash', '[A-Za-z0-9]+');
    Route::delete('/ui-me/downloads/{hash}', [UiDownloadsController::class, 'destroy'])->where('hash', '[A-Za-z0-9]+');
    Route::get('/ui-me/downloads/{hash}/links', [UiDownloadsController::class, 'links'])->where('hash', '[A-Za-z0-9]+');
    Route::get('/ui-me/player/{code}', [\App\Http\Controllers\Ui\UiPlayerController::class, 'show']);
    Route::get('/ui-me/media', [\App\Http\Controllers\Ui\UiMediaController::class, 'index']);
    Route::get('/ui-me/media/files', [\App\Http\Controllers\Ui\UiMediaController::class, 'files']);
    Route::post('/ui-me/media', [\App\Http\Controllers\Ui\UiMediaController::class, 'store']);
    Route::post('/ui-me/media/{id}/retry', [\App\Http\Controllers\Ui\UiMediaController::class, 'retry'])->whereNumber('id');
    Route::get('/ui-me/library', [UiLibraryController::class, 'summary']);
    Route::get('/ui-me/library/codes', [UiLibraryController::class, 'codes']);
    Route::get('/ui-me/library/on-disk', [UiLibraryController::class, 'onDisk']);
    Route::get('/ui-me/library/unmatched', [UiLibraryController::class, 'unmatched']);
    Route::get('/ui-admin/settings', [UiSettingsController::class, 'index']);
    Route::put('/ui-admin/settings', [UiSettingsController::class, 'update']);
    Route::get('/ui-api/{path}', UiApiController::class)->where('path', '.*')->name('ui.api');
});
