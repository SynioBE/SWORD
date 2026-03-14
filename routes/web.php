<?php

use App\Http\Controllers\ServerController;
use App\Http\Controllers\ServerProvisionCallbackController;
use App\Http\Controllers\SiteController;
use App\Http\Controllers\SiteInstallCallbackController;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

Route::inertia('/', 'Welcome', [
    'canRegister' => Features::enabled(Features::registration()),
])->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');

    Route::resource('servers', ServerController::class)
        ->only(['index', 'store', 'show']);

    Route::resource('sites', SiteController::class)
        ->only(['index', 'store', 'show']);
});

// Public, token-secured — no auth required
Route::get('servers/{server}/provision-script', [ServerController::class, 'provisionScript'])
    ->name('servers.provision-script');

Route::get('sites/{site}/install-script', [SiteController::class, 'installScript'])
    ->name('sites.install-script');

// Public callbacks — secured by signature
Route::post('callback/servers/{server}/provision', ServerProvisionCallbackController::class)
    ->name('servers.provision-callback');

Route::post('callback/sites/{site}/install', SiteInstallCallbackController::class)
    ->name('sites.install-callback');

require __DIR__.'/settings.php';
