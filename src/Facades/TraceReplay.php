<?php

namespace TraceReplay\Facades;

use Illuminate\Support\Facades\Facade;
use TraceReplay\Models\Trace;
use TraceReplay\TraceReplayManager;

/**
 * @method static Trace|null start(string $name, array $tags = [])
 * @method static mixed step(string $label, callable $callback, array $extra = [])
 * @method static mixed measure(string $label, callable $callback, array $extra = [])
 * @method static void checkpoint(string $label, array $state = [])
 * @method static static context(array $data)
 * @method static void captureResponseOnLastStep(array $responsePayload, int $httpStatus = 200)
 * @method static void end(string $status = 'success')
 * @method static Trace|null getCurrentTrace()
 *
 * @see TraceReplayManager
 */
class TraceReplay extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'trace-replay';
    }
}
