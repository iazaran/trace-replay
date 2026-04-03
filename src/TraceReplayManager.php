<?php

namespace TraceReplay;

use Illuminate\Support\Facades\DB;
use Throwable;
use TraceReplay\Jobs\PersistTraceStepJob;
use TraceReplay\Models\Trace;
use TraceReplay\Models\TraceStep;
use TraceReplay\Services\NotificationService;

class TraceReplayManager
{
    protected $app;

    protected ?Trace $currentTrace = null;

    /** @var array<int, array<string, mixed>> Buffered steps for batch insert */
    protected array $stepBuffer = [];

    /** @var array<string, mixed> Extra context merged into the next step */
    protected array $pendingContext = [];

    /** @var int Running step order counter */
    protected int $stepCounter = 0;

    /** @var float|null High-resolution start time (microtime) */
    protected ?float $startedAtMicrotime = null;

    public function __construct($app)
    {
        $this->app = $app;
    }

    // ── Lifecycle ────────────────────────────────────────────────────────────

    public function start(string $name, array $tags = []): ?Trace
    {
        if (! config('tracereplay.enabled', true)) {
            return null;
        }

        try {
            $user = null;
            try {
                $user = auth()->user();
            } catch (Throwable) {
            }

            $this->stepCounter = 0;
            $this->stepBuffer = [];
            $this->startedAtMicrotime = microtime(true);

            $this->currentTrace = Trace::create([
                'project_id' => $this->determineProjectId(),
                'name' => $name,
                'tags' => $tags,
                'status' => 'processing',
                'user_id' => $user?->getAuthIdentifier(),
                'user_type' => $user ? \get_class($user) : null,
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'started_at' => now(),
            ]);

            return $this->currentTrace;
        } catch (Throwable $e) {
            $this->handleInternalError($e);

            return null;
        }
    }

    public function step(string $label, callable $callback, array $extra = []): mixed
    {
        if (! $this->currentTrace) {
            return $callback();
        }

        $memBefore = memory_get_usage(true);
        $start = microtime(true);
        $status = 'success';
        $errorReason = null;
        $trackDb = config('tracereplay.track_db_queries', true);

        // Use Laravel's built-in query log rather than DB::listen() to avoid
        // listener accumulation: each additional step() call would register
        // another persistent listener, causing every query to be counted
        // multiple times (once per registered listener).
        if ($trackDb) {
            DB::flushQueryLog();
            DB::enableQueryLog();
        }

        try {
            return $callback();
        } catch (Throwable $e) {
            $status = 'error';
            $errorReason = json_encode([
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => collect(explode("\n", $e->getTraceAsString()))->take(20)->implode("\n"),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            throw $e;
        } finally {
            $durationMs = round((microtime(true) - $start) * 1000, 2);
            $memDelta = memory_get_usage(true) - $memBefore;

            $queryCount = 0;
            $queryTimeMs = 0.0;
            if ($trackDb) {
                $queries = DB::getQueryLog();
                $queryCount = \count($queries);
                $queryTimeMs = round(array_sum(array_column($queries, 'time')), 2);
                DB::disableQueryLog();
                DB::flushQueryLog();
            }

            $this->stepCounter++;

            $stepData = [
                'trace_id' => $this->currentTrace->id,
                'label' => $label,
                'type' => $extra['type'] ?? 'step',
                'status' => $status,
                'duration_ms' => $durationMs,
                'memory_usage' => max(0, $memDelta),
                'db_query_count' => $queryCount,
                'db_query_time_ms' => $queryTimeMs,
                'error_reason' => $errorReason,
                'step_order' => $this->stepCounter,
                'request_payload' => $extra['request_payload'] ?? null,
                'response_payload' => $extra['response_payload'] ?? null,
                'state_snapshot' => [
                    ...($extra['state_snapshot'] ?? []),
                    ...$this->pendingContext,
                ],
            ];

            $this->pendingContext = [];

            $step = new TraceStep($stepData);
            $this->persistStep($step);
        }
    }

    /**
     * Alias of step() for semantic clarity around measuring a single callable.
     */
    public function measure(string $label, callable $callback, array $extra = []): mixed
    {
        return $this->step($label, $callback, $extra);
    }

    /**
     * Record a zero-overhead breadcrumb (no callable) in the timeline.
     */
    public function checkpoint(string $label, array $state = []): void
    {
        if (! $this->currentTrace) {
            return;
        }

        $this->stepCounter++;

        $step = new TraceStep([
            'trace_id' => $this->currentTrace->id,
            'label' => $label,
            'type' => 'checkpoint',
            'status' => 'checkpoint',
            'step_order' => $this->stepCounter,
            'duration_ms' => 0,
            'state_snapshot' => [...$state, ...$this->pendingContext],
        ]);

        $this->pendingContext = [];
        $this->persistStep($step);
    }

    /**
     * Attach arbitrary key/value context that will be merged into the next step's state_snapshot.
     */
    public function context(array $data): static
    {
        $this->pendingContext = [...$this->pendingContext, ...$data];

        return $this;
    }

    /**
     * Attach the response payload onto the most recently saved step (used by middleware terminate()).
     */
    public function captureResponseOnLastStep(array $responsePayload, int $httpStatus = 200): void
    {
        if (! $this->currentTrace) {
            return;
        }

        try {
            $last = TraceStep::where('trace_id', $this->currentTrace->id)
                ->orderBy('step_order', 'desc')
                ->first();

            if ($last) {
                $last->update([
                    'response_payload' => $responsePayload,
                ]);
            }

            $this->currentTrace->update(['http_status' => $httpStatus]);
        } catch (Throwable $e) {
            $this->handleInternalError($e);
        }
    }

    public function end(string $status = 'success'): void
    {
        if (! $this->currentTrace) {
            return;
        }

        try {
            $durationMs = $this->startedAtMicrotime
                ? round((microtime(true) - $this->startedAtMicrotime) * 1000, 2)
                : null;

            $this->currentTrace->update([
                'status' => $status,
                'completed_at' => now(),
                'duration_ms' => $durationMs,
            ]);

            // Fire notification if configured and trace failed
            if ($status === 'error' && config('tracereplay.notifications.on_failure', false)) {
                try {
                    app(NotificationService::class)->notifyFailure($this->currentTrace->fresh(['steps']));
                } catch (Throwable) {
                }
            }
        } catch (Throwable $e) {
            $this->handleInternalError($e);
        } finally {
            $this->currentTrace = null;
            $this->stepBuffer = [];
            $this->stepCounter = 0;
            $this->pendingContext = [];
            $this->startedAtMicrotime = null;
        }
    }

    public function getCurrentTrace(): ?Trace
    {
        return $this->currentTrace;
    }

    // ── Internal Helpers ─────────────────────────────────────────────────────

    protected function persistStep(TraceStep $step): void
    {
        try {
            if (config('tracereplay.queue.enabled') && class_exists(PersistTraceStepJob::class)) {
                dispatch(new PersistTraceStepJob($step->toArray()))
                    ->onConnection(config('tracereplay.queue.connection'))
                    ->onQueue(config('tracereplay.queue.queue'));
            } else {
                $step->save();
            }
        } catch (Throwable $e) {
            $this->handleInternalError($e);
        }
    }

    protected function determineProjectId(): ?string
    {
        return config('tracereplay.project_id');
    }

    /**
     * Graceful degradation: log but never let tracing crash the application.
     */
    protected function handleInternalError(Throwable $e): void
    {
        if (function_exists('logger')) {
            logger()->error('[TraceReplay] Internal error: '.$e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        }
    }
}
