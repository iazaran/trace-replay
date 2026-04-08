<?php

namespace TraceReplay\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use TraceReplay\Models\TraceStep;

class PersistTraceStepJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    protected array $stepData;

    public function __construct(array $stepData)
    {
        $this->stepData = $stepData;
    }

    public function handle(): void
    {
        // Actually save the model
        $step = new TraceStep($this->stepData);
        $step->save();
    }
}
