<?php

use Illuminate\Support\Facades\Route;
use TraceReplay\Http\Controllers\Api\McpController;

Route::group([
    'prefix' => 'api/trace-replay/mcp',
    'as' => 'trace-replay.api.mcp.',
    'middleware' => config('trace-replay.api_middleware', ['api']),
], function () {
    Route::post('/', [McpController::class, 'handleRpc'])->name('rpc');

    // REST fallbacks if preferred over RPC
    Route::get('/traces', [McpController::class, 'listTraces']);
    Route::get('/traces/{trace}/context', [McpController::class, 'getContext']);
    Route::post('/traces/{trace}/replay', [McpController::class, 'triggerReplay']);
    Route::get('/traces/{trace}/fix-prompt', [McpController::class, 'generateFixPrompt']);
});
