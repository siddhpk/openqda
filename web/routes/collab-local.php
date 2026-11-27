<?php

use App\Http\Controllers\CollabLocalController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Laptop collaboration: storage API for the node agent
|--------------------------------------------------------------------------
|
| Prefix /_collab/local, middleware "collab.local" (loopback collab channel
| plus the installation's token), registered in RouteServiceProvider
| outside the web group: no session, cookies, CSRF or access-code gate.
*/
Route::get('/roster', [CollabLocalController::class, 'roster']);
Route::post('/apply', [CollabLocalController::class, 'apply']);
Route::post('/ops', [CollabLocalController::class, 'ops']);
Route::get('/vv', [CollabLocalController::class, 'vv']);
Route::get('/blob/{sha}', [CollabLocalController::class, 'blobGet'])->where('sha', '[0-9a-f]{64}');
Route::put('/blob/{sha}', [CollabLocalController::class, 'blobPut'])->where('sha', '[0-9a-f]{64}');
Route::post('/join', [CollabLocalController::class, 'join']);
Route::post('/joined', [CollabLocalController::class, 'joined']);
Route::post('/join-failed', [CollabLocalController::class, 'joinFailed']);
Route::get('/doc', [CollabLocalController::class, 'doc']);
Route::post('/flush', [CollabLocalController::class, 'flush']);
