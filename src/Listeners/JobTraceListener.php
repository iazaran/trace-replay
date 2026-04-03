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
        if (! config('tracereplay.auto_trace.jobs', true)) {
            return;
        }

        $jobName = $this->resolveJobName($event->job->payload());

        TraceReplay::start("Job: {$jobName}", [
            'queue' => $event->job->getQueue(),
            'connection' => $event->connectionName,
            'job_id' => $event->job->getJobId(),
        ]);

        TraceReplay::checkpoint('Job Started', [
            'payload' => $event->job->payload(),
        ]);
    }

    public function onJobProcessed(JobProcessed $_event): void
    {
        if (! config('tracereplay.auto_trace.jobs', true)) {
            return;
        }

        TraceReplay::checkpoint('Job Completed');
        TraceReplay::end('success');
    }

    public function onJobFailed(JobFailed $event): void
    {
        if (! config('tracereplay.auto_trace.jobs', true)) {
            return;
        }

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
