<?php

use Illuminate\Support\Facades\Route;
use TraceReplay\Http\Controllers\Api\McpController;

Route::group([
    'prefix' => 'api/trace-replay',
    'as' => 'trace-replay.api.',
    'middleware' => array_merge(
        config('trace-replay.api.middleware', ['api']),
        ['throttle:60,1']
    ),
], function () {
    Route::post('/mcp', [McpController::class, 'handleRpc'])->name('mcp.rpc');

    // REST fallbacks
    Route::get('/traces', [McpController::class, 'listTraces'])->name('list');
    Route::get('/traces/{trace}/context', [McpController::class, 'getContext'])->name('context');
    Route::post('/traces/{trace}/replay', [McpController::class, 'triggerReplay'])->name('replay');
    Route::get('/traces/{trace}/fix-prompt', [McpController::class, 'generateFixPrompt'])->name('fix-prompt');
});
