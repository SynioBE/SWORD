<?php

use App\Http\Controllers\BackupDestinationController;
use App\Http\Controllers\BackupScheduleController;
use App\Http\Controllers\BackupScheduleIndexController;
use App\Http\Controllers\CloudflareController;
use App\Http\Controllers\CloudflareDnsRecordController;
use App\Http\Controllers\CloudflarePurgeCacheController;
use App\Http\Controllers\ServerController;
use App\Http\Controllers\ServerProvisionCallbackController;
use App\Http\Controllers\SiteController;
use App\Http\Controllers\SiteInstallCallbackController;
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

    Route::post('servers/{server}/backup-schedules/{backup_schedule}/run', [BackupScheduleController::class, 'run'])
        ->name('servers.backup-schedules.run');

    Route::get('backup-destinations/generate-name', [BackupDestinationController::class, 'generateName'])
        ->name('backup-destinations.generate-name');

    Route::resource('backup-destinations', BackupDestinationController::class)
        ->only(['index', 'store', 'show', 'update', 'destroy']);

    Route::resource('sites', SiteController::class)
        ->only(['index', 'store', 'show']);

    Route::get('cloudflare', [CloudflareController::class, 'index'])->name('cloudflare.index');
    Route::get('cloudflare/{integration}', [CloudflareController::class, 'zones'])->name('cloudflare.zones');
    Route::get('cloudflare/{integration}/{zone}', [CloudflareController::class, 'show'])->name('cloudflare.show');
    Route::post('cloudflare/{integration}/{zone}/purge-cache', CloudflarePurgeCacheController::class)->name('cloudflare.purge-cache');
    Route::post('cloudflare/{integration}/{zone}/dns-records', [CloudflareDnsRecordController::class, 'store'])->name('cloudflare.dns-records.store');
    Route::patch('cloudflare/{integration}/{zone}/dns-records/{record}', [CloudflareDnsRecordController::class, 'update'])->name('cloudflare.dns-records.update');
    Route::delete('cloudflare/{integration}/{zone}/dns-records/{record}', [CloudflareDnsRecordController::class, 'destroy'])->name('cloudflare.dns-records.destroy');
});

// Public, token-secured — no auth required
Route::get('servers/{server}/scripts/provision', [ServerController::class, 'provisionScript'])
    ->name('servers.scripts.provision');

Route::get('sites/{site}/scripts/install', [SiteController::class, 'installScript'])
    ->name('sites.scripts.install');

// Public callbacks — secured by signature
Route::post('servers/{server}/callbacks/provision', ServerProvisionCallbackController::class)
    ->name('servers.callbacks.provision');

Route::post('sites/{site}/callbacks/install', SiteInstallCallbackController::class)
    ->name('sites.callbacks.install');

require __DIR__.'/settings.php';
