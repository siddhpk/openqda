<?php

/*
|--------------------------------------------------------------------------
| Embeddings & Autocoding module
|--------------------------------------------------------------------------
|
| Loaded from web.php inside the authenticated route group. While
| AI_ENABLED is unset, every route except ai.status answers 404; until the
| project owner has enabled embeddings for a project, 403.
|
*/

use App\Http\Controllers\AiController;
use App\Http\Controllers\AutocodeController;
use Illuminate\Support\Facades\Route;

Route::get('/projects/{project}/ai/status', [AiController::class, 'status'])->name('ai.status');
Route::get('/projects/{project}/autocode', [AutocodeController::class, 'show'])->name('autocode.show');
Route::middleware('throttle:'.config('ai.limits.requests_per_minute', 60).',1')->group(function () {
    Route::post('/projects/{project}/ai/consent', [AiController::class, 'consent'])->name('ai.consent');
    Route::put('/projects/{project}/ai/redaction-terms', [AiController::class, 'redactionTerms'])->name('ai.redaction-terms');
    Route::delete('/projects/{project}/ai/cache', [AiController::class, 'purge'])->name('ai.purge');
    Route::post('/projects/{project}/ai/embed', [AiController::class, 'embed'])->name('ai.embed');
    Route::post('/projects/{project}/autocode/corpus', [AutocodeController::class, 'corpus'])->name('autocode.corpus');
    Route::post('/projects/{project}/autocode/runs', [AutocodeController::class, 'storeRun'])->name('autocode.runs.store');
    Route::patch('/projects/{project}/autocode/runs/{run}', [AutocodeController::class, 'updateRun'])->name('autocode.runs.update');
    Route::post('/projects/{project}/autocode/runs/{run}/accept', [AutocodeController::class, 'accept'])->name('autocode.runs.accept');
    Route::post('/projects/{project}/autocode/runs/{run}/decisions', [AutocodeController::class, 'decisions'])->name('autocode.runs.decisions');
});
Route::get('/projects/{project}/autocode/runs', [AutocodeController::class, 'runs'])->name('autocode.runs');
