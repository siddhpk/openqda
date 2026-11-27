<?php

use App\Http\Controllers\CollabController;
use App\Http\Controllers\CollabDocController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Laptop collaboration
|--------------------------------------------------------------------------
|
| Loaded from routes/desktop.php. The Collaboration page is for the person
| at this computer only (desktop.host answers 404 everywhere else). The
| live channel and co-editing are for everyone working in a collaborative
| project on this laptop. Nothing here does anything outside the laptop
| edition (Collab::enabled()).
*/
Route::middleware(['auth', 'desktop.host'])->prefix('desktop/collab')->controller(CollabController::class)->group(function () {
    Route::get('/', 'show')->name('desktop.collab');
    Route::get('/status', 'status')->name('desktop.collab.status');
    Route::post('/tailscale/connect', 'connect')->name('desktop.collab.connect');
    Route::post('/tailscale/disconnect', 'disconnect')->name('desktop.collab.disconnect');
    Route::post('/tailscale/tag', 'tag')->name('desktop.collab.tag');
    Route::post('/settings', 'settings')->name('desktop.collab.settings');
    Route::post('/join', 'join')->name('desktop.collab.join');
    Route::delete('/joins/{join}', 'dismissJoin')->name('desktop.collab.dismiss');
    Route::post('/sync', 'sync')->name('desktop.collab.sync');
    Route::post('/projects/{project}/enable', 'enable')->name('desktop.collab.enable');
    Route::post('/projects/{project}/invites', 'invite')->name('desktop.collab.invite');
    Route::delete('/projects/{project}/invites/{invite}', 'revoke')->name('desktop.collab.revoke');
    Route::post('/projects/{project}/members', 'member')->name('desktop.collab.member');
    Route::post('/projects/{project}/leave', 'leave')->name('desktop.collab.leave');
    Route::get('/projects/{project}/reviews', 'reviews')->name('desktop.collab.reviews');
    Route::post('/projects/{project}/reviews', 'decide')->name('desktop.collab.decide');
    Route::post('/projects/{project}/orphans', 'orphan')->name('desktop.collab.orphan');
});

Route::middleware(['auth'])->controller(CollabDocController::class)->group(function () {
    Route::get('/projects/{project}/collab/live', 'live')->name('collab.live');
    Route::get('/collab/docs', 'open')->name('collab.docs.open');
    Route::post('/collab/docs/updates', 'append')->name('collab.docs.append');
});
