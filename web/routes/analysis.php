<?php

/*
|--------------------------------------------------------------------------
| Analysis Toolkit module
|--------------------------------------------------------------------------
|
| Loaded from web.php inside the authenticated route group: research design,
| vocabulary, code relationships, concepts, propositions, evidence, cases
| and the mind map.
|
*/

use App\Http\Controllers\CaseController;
use App\Http\Controllers\CodeRelationController;
use App\Http\Controllers\ConceptController;
use App\Http\Controllers\EvidenceController;
use App\Http\Controllers\MapController;
use App\Http\Controllers\PropositionController;
use App\Http\Controllers\ResearchDesignController;
use App\Http\Controllers\VocabularyController;
use Illuminate\Support\Facades\Route;

/*
 * Concepts, propositions, evidence and cases (method-neutral analysis objects)
 */
Route::get('/projects/{project}/concepts', [ConceptController::class, 'page'])->name('concepts.page');
Route::get('/projects/{project}/concepts/{concept}', [ConceptController::class, 'show'])->name('concepts.show');
Route::post('/projects/{project}/concepts', [ConceptController::class, 'store'])->name('concepts.store');
Route::put('/projects/{project}/concepts/{concept}', [ConceptController::class, 'update'])->name('concepts.update');
Route::delete('/projects/{project}/concepts/{concept}', [ConceptController::class, 'destroy'])->name('concepts.destroy');
Route::get('/projects/{project}/propositions/{proposition}', [PropositionController::class, 'show'])->name('propositions.show');
Route::post('/projects/{project}/propositions', [PropositionController::class, 'store'])->name('propositions.store');
Route::put('/projects/{project}/propositions/{proposition}', [PropositionController::class, 'update'])->name('propositions.update');
Route::delete('/projects/{project}/propositions/{proposition}', [PropositionController::class, 'destroy'])->name('propositions.destroy');
Route::get('/projects/{project}/evidence/candidates', [EvidenceController::class, 'candidates'])->name('evidence.candidates');
Route::post('/projects/{project}/evidence', [EvidenceController::class, 'store'])->name('evidence.store');
Route::put('/projects/{project}/evidence/{evidence}', [EvidenceController::class, 'update'])->name('evidence.update');
Route::delete('/projects/{project}/evidence/{evidence}', [EvidenceController::class, 'destroy'])->name('evidence.destroy');
Route::get('/projects/{project}/cases', [CaseController::class, 'page'])->name('cases.page');
Route::post('/projects/{project}/cases', [CaseController::class, 'store'])->name('cases.store');
Route::get('/projects/{project}/cases/matrix', [CaseController::class, 'matrix'])->name('cases.matrix');
Route::get('/projects/{project}/cases/cell', [CaseController::class, 'cell'])->name('cases.cell');
Route::put('/projects/{project}/cases/assessment', [CaseController::class, 'assess'])->name('cases.assess');
Route::put('/projects/{project}/cases/{case}', [CaseController::class, 'update'])->name('cases.update');
Route::delete('/projects/{project}/cases/{case}', [CaseController::class, 'destroy'])->name('cases.destroy');

/*
 * Research design (versioned, in the researchers' own words) and analysis plan
 */
Route::get('/projects/{project}/design', [ResearchDesignController::class, 'show'])->name('design.show');
Route::get('/projects/{project}/design/summary', [ResearchDesignController::class, 'summary'])->name('design.summary');
Route::put('/projects/{project}/design', [ResearchDesignController::class, 'update'])->name('design.update');
Route::put('/projects/{project}/design/plan', [ResearchDesignController::class, 'plan'])->name('design.plan');

/*
 * Analytic vocabulary of a project (code/relation/memo types, statuses, fields)
 */
Route::get('/projects/{project}/vocabulary', [VocabularyController::class, 'show'])->name('vocabulary.show');
Route::put('/projects/{project}/vocabulary', [VocabularyController::class, 'update'])->name('vocabulary.update');
Route::delete('/projects/{project}/vocabulary', [VocabularyController::class, 'reset'])->name('vocabulary.reset');

/*
 * Code relationships (non-hierarchical links between codes)
 */
Route::get('/projects/{project}/code-relations', [CodeRelationController::class, 'index'])->name('code-relations.index');
Route::post('/projects/{project}/code-relations', [CodeRelationController::class, 'store'])->name('code-relations.store');
Route::patch('/projects/{project}/code-relations/{relation}', [CodeRelationController::class, 'update'])->name('code-relations.update');
Route::delete('/projects/{project}/code-relations/{relation}', [CodeRelationController::class, 'destroy'])->name('code-relations.destroy');

/**
 * Mind map
 */
Route::get('/projects/{project}/map', [MapController::class, 'show'])->name('map.show');
Route::post('/projects/{project}/map/import', [MapController::class, 'import'])->name('map.import');
