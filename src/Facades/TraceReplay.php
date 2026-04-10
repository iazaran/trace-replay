<?php

namespace TraceReplay\Facades;

use Illuminate\Support\Facades\Facade;
use TraceReplay\Testing\TraceReplayFake;
use TraceReplay\TraceReplayManager;

/**
 * @method static \TraceReplay\Models\Trace|null start(string $name, array $tags = [], string $type = 'http', bool $forceSample = false)
 * @method static mixed step(string $label, callable $callback, array $extra = [])
 * @method static mixed measure(string $label, callable $callback, array $extra = [])
 * @method static void checkpoint(string $label, array $state = [])
 * @method static \TraceReplay\TraceReplayManager context(array $data)
 * @method static void captureResponseOnLastStep(array $responsePayload, int $httpStatus = 200)
 * @method static void captureException(\Throwable $exception)
 * @method static void end(string $status = 'success')
 * @method static \TraceReplay\Models\Trace|null getCurrentTrace()
 * @method static void setTraceParent(?string $traceParent)
 * @method static void setWorkspaceId(?string $id)
 * @method static void setProjectId(?string $id)
 * @method static void recordEvent(mixed $event)
 * @method static \TraceReplay\Testing\TraceReplayFake fake()
 *
 * @see TraceReplayManager
 */
class TraceReplay extends Facade
{
    /**
     * Replace the bound instance with a fake.
     */
    public static function fake(): TraceReplayFake
    {
        static::swap($fake = new TraceReplayFake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return 'trace-replay';
    }
}
