<?php

use App\Http\Controllers\MdbOperatorEntryController;
use App\Http\Controllers\MdbWorkflowController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'active'])->prefix('mdb-workflow')->name('mdb-workflow.')->controller(MdbWorkflowController::class)->group(function () {
    Route::get('/', 'index')->name('index');
    Route::get('/create', 'create')->name('create');
    Route::post('/', 'store')->name('store');
    Route::get('/configuration/{project}', 'configuration')->name('config');
    Route::put('/configuration/{project}', 'saveConfiguration')->name('config.update');
    Route::post('/templates', 'storeTemplate')->name('templates.store');
    Route::get('/exports/{export}/download', 'download')->name('exports.download');
    Route::post('/exports/{export}/retry', 'retryExport')->middleware('throttle:10,1')->name('exports.retry');
    Route::post('/exports/{export}/analysis', 'analyze')->name('analysis.store');
    Route::get('/{batch}', 'show')->name('show');
    Route::get('/{batch}/advanced', 'advanced')->name('advanced');
    Route::get('/{batch}/review', 'review')->name('review');
    Route::post('/{batch}/survey-decision', 'surveyDecision')->name('survey.decision');
    Route::get('/{batch}/entry-data', [MdbOperatorEntryController::class, 'data'])->name('entry.data');
    Route::post('/{batch}/entry-review', [MdbOperatorEntryController::class, 'confirmReview'])->name('entry.review');
    Route::post('/{batch}/entry-headers', [MdbOperatorEntryController::class, 'header'])->name('entry.headers.store');
    Route::put('/{batch}/entry-headers/{transformer}', [MdbOperatorEntryController::class, 'header'])->name('entry.headers.update');
    Route::post('/{batch}/transformers/{transformer}/rows', [MdbOperatorEntryController::class, 'save'])->name('entry.rows.store');
    Route::put('/{batch}/transformers/{transformer}/rows/{row}', [MdbOperatorEntryController::class, 'save'])->name('entry.rows.update');
    Route::delete('/{batch}/transformers/{transformer}/rows/{row}', [MdbOperatorEntryController::class, 'destroy'])->name('entry.rows.destroy');
    Route::post('/{batch}/sources', 'upload')->name('sources.store');
    Route::get('/{batch}/sources/{source}', 'source')->name('source');
    Route::post('/{batch}/sources/{source}/retry', 'retrySource')->name('sources.retry');
    Route::post('/{batch}/transformers', 'saveTransformer')->name('transformers.store');
    Route::put('/{batch}/transformers/{transformer}', 'saveTransformer')->name('transformers.update');
    Route::post('/{batch}/transformers/{transformer}/sections', 'saveSection')->name('sections.store');
    Route::put('/{batch}/transformers/{transformer}/sections/{section}', 'saveSection')->name('sections.update');
    Route::delete('/{batch}/transformers/{transformer}/sections/{section}', 'removeSection')->name('sections.destroy');
    Route::post('/{batch}/transformers/{transformer}/pages', 'associatePage')->name('pages.store');
    Route::delete('/{batch}/transformers/{transformer}/pages/{pageIndex}', 'removePage')->whereNumber('pageIndex')->name('pages.destroy');
    Route::post('/{batch}/transformers/{transformer}/pv', 'savePv')->name('pv.store');
    Route::put('/{batch}/transformers/{transformer}/pv/{pv}', 'savePv')->name('pv.update');
    Route::post('/{batch}/waypoints/{waypoint}/corrections', 'correctWaypoint')->name('waypoints.corrections.store');
    Route::post('/{batch}/transformers/{transformer}/sections/{section}/length-approval', 'approveLength')->name('sections.length.approve');
    Route::post('/{batch}/transformers/{transformer}/sections/{section}/consumers/{consumer}/approval', 'approveDemand')->name('consumers.approve');
    Route::post('/{batch}/transition', 'transition')->name('transition');
    Route::post('/{batch}/exports', 'export')->middleware('throttle:10,1')->name('exports.store');
});
