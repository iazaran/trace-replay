<?php

namespace TraceReplay\Listeners;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Jobs\SyncJob;
use TraceReplay\Facades\TraceReplay;
use WeakMap;

class JobTraceListener
{
    /**
     * Sync jobs that started while another trace (an HTTP request, a command
     * or a worker job) was active, keyed by the job object so entries go away
     * with the job. These are recorded inside that trace rather than taking it
     * over.
     *
     * @var WeakMap<Job, string>|null
     */
    protected static ?WeakMap $nestedJobs = null;

    public function onJobProcessing(JobProcessing $event): void
    {
        $jobName = $this->resolveJobName($event->job->payload());

        $state = [
            'queue' => $event->job->getQueue(),
            'job_id' => $event->job->getJobId(),
        ];

        if ($this->runsInsideActiveTrace($event->job)) {
            static::nestedJobs()[$event->job] = $jobName;

            $state['connection'] = $event->connectionName;

            if (config('trace-replay.auto_trace.capture_job_payload', false)) {
                $state['payload'] = $event->job->payload();
            }

            TraceReplay::checkpoint("Job Started: {$jobName}", $state);

            return;
        }

        TraceReplay::start("Job: {$jobName}", [
            'queue' => $event->job->getQueue(),
            'connection' => $event->connectionName,
            'job_id' => $event->job->getJobId(),
        ], 'job');

        if (config('trace-replay.auto_trace.capture_job_payload', false)) {
            $state['payload'] = $event->job->payload();
        }

        TraceReplay::checkpoint('Job Started', $state);
    }

    public function onJobProcessed(JobProcessed $event): void
    {
        if (($jobName = $this->pullNestedJob($event->job)) !== null) {
            TraceReplay::checkpoint("Job Completed: {$jobName}");

            return;
        }

        TraceReplay::checkpoint('Job Completed');
        TraceReplay::end('success');
    }

    public function onJobFailed(JobFailed $event): void
    {
        $state = ['error' => $event->exception->getMessage()];

        if (($jobName = $this->pullNestedJob($event->job)) !== null) {
            TraceReplay::checkpoint("Job Failed: {$jobName}", $state);

            return;
        }

        TraceReplay::checkpoint('Job Failed', $state);
        TraceReplay::end('error');
    }

    /**
     * A sync job runs inline, inside whatever is executing it. If that context
     * already has a trace, the job belongs to it.
     */
    protected function runsInsideActiveTrace(Job $job): bool
    {
        return $job instanceof SyncJob && TraceReplay::getCurrentTrace() !== null;
    }

    protected function pullNestedJob(Job $job): ?string
    {
        $nestedJobs = static::nestedJobs();

        if (! isset($nestedJobs[$job])) {
            return null;
        }

        $jobName = $nestedJobs[$job];
        unset($nestedJobs[$job]);

        return $jobName;
    }

    /**
     * @return WeakMap<Job, string>
     */
    protected static function nestedJobs(): WeakMap
    {
        return static::$nestedJobs ??= new WeakMap;
    }

    private function resolveJobName(array $payload): string
    {
        $class = $payload['displayName'] ?? $payload['job'] ?? 'UnknownJob';

        return class_basename($class);
    }
}
