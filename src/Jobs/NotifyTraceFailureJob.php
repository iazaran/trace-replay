<?php

namespace TraceReplay\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use TraceReplay\Models\Trace;
use TraceReplay\Services\NotificationService;

class NotifyTraceFailureJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(protected string $traceId) {}

    public function handle(NotificationService $notificationService): void
    {
        $trace = Trace::with('steps')->find($this->traceId);

        if (! $trace) {
            return;
        }

        $notificationService->notifyFailure($trace);
    }
}
