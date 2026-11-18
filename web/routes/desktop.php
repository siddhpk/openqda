<?php

use App\Http\Controllers\DesktopController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Laptop Edition module
|--------------------------------------------------------------------------
|
| Loaded at the end of web.php. Inactive unless OPENQDA_DESKTOP is set by
| the launcher.
|
| Sharing controls exist only for the person at the computer running the
| laptop edition (desktop.host answers 404 everywhere else). The access-code
| page is what visitors see first when they open the sharing link.
*/
Route::middleware(['auth', 'desktop.host'])->prefix('desktop')->group(function () {
    Route::get('/sharing', [DesktopController::class, 'show'])->name('desktop.sharing');
    Route::get('/sharing/status', [DesktopController::class, 'status'])->name('desktop.sharing.status');
    Route::post('/sharing/start', [DesktopController::class, 'start'])->name('desktop.sharing.start');
    Route::post('/sharing/stop', [DesktopController::class, 'stop'])->name('desktop.sharing.stop');
    Route::post('/sharing/code', [DesktopController::class, 'rotate'])->name('desktop.sharing.code');
    Route::post('/autostart', [DesktopController::class, 'autostart'])->name('desktop.autostart');
    Route::post('/ai', [DesktopController::class, 'ai'])->name('desktop.ai');
    Route::post('/ai/pull', [DesktopController::class, 'pullModel'])->name('desktop.ai.pull');
});
Route::post('/desktop/shutdown', [DesktopController::class, 'shutdown'])
    ->middleware('desktop.host')->name('desktop.shutdown');
Route::get('/share/enter', [DesktopController::class, 'gate'])->name('desktop.gate');
Route::post('/share/enter', [DesktopController::class, 'enter'])
    ->middleware('throttle:30,1')->name('desktop.enter');
