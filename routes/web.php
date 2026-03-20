<?php

use App\Http\Controllers\BackupDestinationController;
use App\Http\Controllers\BackupScheduleController;
use App\Http\Controllers\BackupScheduleIndexController;
use App\Http\Controllers\ServerController;
use App\Http\Controllers\ServerProvisionCallbackController;
use App\Http\Controllers\SiteController;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

// Route::inertia('/', 'Welcome', [
//     'canRegister' => Features::enabled(Features::registration()),
// ])->name('home');

Route::redirect('/', '/servers')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {

    Route::get('servers/generate-name', [ServerController::class, 'generateName'])
        ->name('servers.generate-name');

    Route::resource('servers', ServerController::class)
        ->only(['index', 'store', 'show', 'destroy']);

    Route::get('backup-schedules', BackupScheduleIndexController::class)
        ->name('backup-schedules.index');

    Route::resource('servers.backup-schedules', BackupScheduleController::class)
        ->only(['store', 'destroy']);

    Route::get('backup-destinations/generate-name', [BackupDestinationController::class, 'generateName'])
        ->name('backup-destinations.generate-name');

    Route::resource('backup-destinations', BackupDestinationController::class)
        ->only(['index', 'store', 'show', 'destroy']);

    Route::resource('sites', SiteController::class)
        ->only(['index', 'store', 'show']);
});

// Public, token-secured — no auth required
Route::get('servers/{server}/scripts/provision', [ServerController::class, 'provisionScript'])
    ->name('servers.scripts.provision');

// Route::get('sites/{site}/scripts/install', [SiteController::class, 'installScript'])
//     ->name('sites.scripts.install');

// Public callbacks — secured by signature
Route::post('servers/{server}/callbacks/provision', ServerProvisionCallbackController::class)
    ->name('servers.callbacks.provision');

// Route::post('sites/{site}/callbacks/install', SiteInstallCallbackController::class)
//     ->name('sites.callbacks.install');

require __DIR__.'/settings.php';
