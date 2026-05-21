<?php

namespace TraceReplay\Listeners;

use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use TraceReplay\Facades\TraceReplay;

class JobTraceListener
{
    public function onJobProcessing(JobProcessing $event): void
    {
        $jobName = $this->resolveJobName($event->job->payload());

        TraceReplay::start("Job: {$jobName}", [
            'queue' => $event->job->getQueue(),
            'connection' => $event->connectionName,
            'job_id' => $event->job->getJobId(),
        ], 'job');

        $state = [
            'queue' => $event->job->getQueue(),
            'job_id' => $event->job->getJobId(),
        ];

        if (config('trace-replay.auto_trace.capture_job_payload', false)) {
            $state['payload'] = $event->job->payload();
        }

        TraceReplay::checkpoint('Job Started', $state);
    }

    public function onJobProcessed(JobProcessed $_event): void
    {
        TraceReplay::checkpoint('Job Completed');
        TraceReplay::end('success');
    }

    public function onJobFailed(JobFailed $event): void
    {
        TraceReplay::checkpoint('Job Failed', [
            'error' => $event->exception->getMessage(),
        ]);
        TraceReplay::end('error');
    }

    private function resolveJobName(array $payload): string
    {
        $class = $payload['displayName'] ?? $payload['job'] ?? 'UnknownJob';

        return class_basename($class);
    }
}
