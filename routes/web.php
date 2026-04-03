<?php

use Illuminate\Support\Facades\Route;
use TraceReplay\Http\Controllers\DashboardController;
use TraceReplay\Http\Middleware\TraceReplayAuthMiddleware;

$middleware = array_merge(
    config('tracereplay.middleware', ['web']),
    [TraceReplayAuthMiddleware::class]
);

Route::group([
    'prefix'     => 'tracereplay',
    'as'         => 'tracereplay.',
    'middleware' => $middleware,
], function () {
    Route::get('/',                                   [DashboardController::class, 'index'])->name('index');
    Route::get('/traces/{id}',                        [DashboardController::class, 'show'])->name('show');
    Route::post('/traces/{id}/replay',                [DashboardController::class, 'replay'])->name('replay');
    Route::post('/traces/{id}/ai-prompt',             [DashboardController::class, 'generatePrompt'])->name('ai.prompt');
    Route::get('/stats',                              [DashboardController::class, 'stats'])->name('stats');
    Route::get('/traces/{id}/export',                 [DashboardController::class, 'export'])->name('export');
});
