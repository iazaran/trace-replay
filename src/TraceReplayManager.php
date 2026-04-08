<?php

namespace TraceReplay;

use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyForgotten;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Http\Client\Events\RequestSending as HttpRequestSending;
use Illuminate\Http\Client\Events\ResponseReceived as HttpResponseReceived;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;
use TraceReplay\Jobs\PersistTraceStepJob;
use TraceReplay\Models\Trace;
use TraceReplay\Models\TraceStep;
use TraceReplay\Services\NotificationService;

class TraceReplayManager
{
    protected $app;

    protected ?Trace $currentTrace = null;

    protected ?string $workspaceId = null;

    protected ?string $projectId = null;

    /** @var TraceStep|null Reference to the last persisted step */
    protected ?TraceStep $lastStep = null;

    /** @var array<int, array<string, mixed>> Buffered steps for batch insert */
    protected array $stepBuffer = [];

    /** @var array<string, mixed> Extra context merged into the next step */
    protected array $pendingContext = [];

    /** @var int Running step order counter */
    protected int $stepCounter = 0;

    /** @var float|null High-resolution start time (microtime) */
    protected ?float $startedAtMicrotime = null;

    /** @var string|null Incoming W3C traceparent header */
    protected ?string $traceParent = null;

    /** @var array<int, array<string, mixed>> Stack of active step data frames */
    protected array $stepStack = [];

    /**
     * Nesting depth for start() calls.
     * When > 0 the current trace was started by a parent context (e.g. middleware)
     * and an inner start()/end() pair should be non-destructive.
     */
    protected int $traceDepth = 0;

    public function __construct($app)
    {
        $this->app = $app;
    }

    // ── Lifecycle ────────────────────────────────────────────────────────────

    public function start(string $name, array $tags = [], bool $forceSample = false): ?Trace
    {
        if (! config('trace-replay.enabled', true)) {
            return null;
        }

        // If a trace is already active (e.g. started by TraceMiddleware), do NOT create a
        // second one — just update the name/tags, increment the nesting depth, and return
        // the existing trace. This allows controllers to call start() for contextual labelling
        // without conflicting with the middleware's lifecycle management.
        if ($this->currentTrace) {
            $this->traceDepth++;
            try {
                $this->currentTrace->update(array_filter([
                    'name' => $name,
                    'tags' => ! empty($tags) ? $tags : null,
                ]));
            } catch (Throwable $e) {
                $this->handleInternalError($e);
            }

            return $this->currentTrace;
        }

        // Respect sampling rate unless forced (e.g. manual calls or specific job types)
        if (! $forceSample) {
            $sampleRate = (float) config('trace-replay.sample_rate', 1.0);
            if ($sampleRate < 1.0 && mt_rand() / mt_getrandmax() > $sampleRate) {
                return null;
            }
        }

        try {
            $user = null;
            try {
                $user = auth()->user();
            } catch (Throwable) {
            }

            $this->stepCounter = 0;
            $this->stepBuffer = [];
            $this->lastStep = null;
            $this->stepStack = [];
            $this->startedAtMicrotime = microtime(true);

            $this->currentTrace = Trace::create([
                'project_id' => $this->projectId ?? $this->determineProjectId(),
                'name' => $name,
                'tags' => $tags,
                'status' => 'processing',
                'user_id' => $user?->getAuthIdentifier(),
                'user_type' => $user ? \get_class($user) : null,
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'trace_parent' => $this->traceParent,
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
        $trackDb = config('trace-replay.track_db_queries', true);

        // Use Laravel's built-in query log rather than DB::listen() to avoid
        // listener accumulation: each additional step() call would register
        // another persistent listener, causing every query to be counted
        // multiple times (once per registered listener).
        $dbQueriesBefore = 0;
        if ($trackDb) {
            DB::enableQueryLog();
            $dbQueriesBefore = count(DB::getQueryLog());
        }

        // Push a new frame onto the stack to collect events for this step and its children
        $this->stepStack[] = [
            'db_queries_before' => $dbQueriesBefore,
            'cache_calls' => [],
            'cache_hit_count' => 0,
            'cache_miss_count' => 0,
            'http_calls' => [],
            'mail_calls' => [],
            'log_calls' => [],
        ];

        try {
            return $callback();
        } catch (Throwable $e) {
            $status = 'error';
            $errorReason = [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => collect(explode("\n", $e->getTraceAsString()))->take(20)->implode("\n"),
            ];
            throw $e;
        } finally {
            $frame = array_pop($this->stepStack);
            $durationMs = round((microtime(true) - $start) * 1000, 2);
            $memDelta = memory_get_usage(true) - $memBefore;

            // Guard: $frame may be null if stepStack was corrupted by a previous exception
            if ($frame === null) {
                $frame = [
                    'db_queries_before' => 0,
                    'cache_calls' => [],
                    'cache_hit_count' => 0,
                    'cache_miss_count' => 0,
                    'http_calls' => [],
                    'mail_calls' => [],
                    'log_calls' => [],
                ];
            }

            $queryCount = 0;
            $queryTimeMs = 0.0;
            $queries = [];
            if ($trackDb) {
                $queries = array_slice(DB::getQueryLog(), (int) $frame['db_queries_before']);
                $queryCount = \count($queries);
                $queryTimeMs = round(array_sum(array_column($queries, 'time')), 2);
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
                'db_queries' => $queryCount > 0 ? $queries : null,
                'db_query_time_ms' => $queryTimeMs,
                'cache_calls' => count($frame['cache_calls']) > 0 ? $frame['cache_calls'] : null,
                'cache_hit_count' => $frame['cache_hit_count'],
                'cache_miss_count' => $frame['cache_miss_count'],
                'http_calls' => count($frame['http_calls']) > 0 ? array_values($frame['http_calls']) : null,
                'mail_calls' => count($frame['mail_calls']) > 0 ? $frame['mail_calls'] : null,
                'log_calls' => count($frame['log_calls']) > 0 ? $frame['log_calls'] : null,
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
            $this->lastStep = $step;
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
            'memory_usage' => 0,
            'db_query_count' => 0,
            'db_query_time_ms' => 0,
            'cache_hit_count' => 0,
            'cache_miss_count' => 0,
            'state_snapshot' => [...$state, ...$this->pendingContext],
        ]);

        $this->pendingContext = [];
        $this->lastStep = $step;
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
            // Update the last step in the buffer if batching is active
            if (! config('trace-replay.queue.enabled') && ! empty($this->stepBuffer)) {
                $lastIndex = count($this->stepBuffer) - 1;
                $this->stepBuffer[$lastIndex]['response_payload'] = $responsePayload;
            } elseif ($this->lastStep) {
                // Otherwise update the persisted/queued model
                $this->lastStep->update([
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

        // If we are inside a nested start() (e.g. controller called start() while middleware
        // already owns the trace), just decrement the depth and return — do NOT tear down the
        // trace that the outer context is still managing.
        if ($this->traceDepth > 0) {
            $this->traceDepth--;

            return;
        }

        $this->flushStepBuffer();

        try {
            $durationMs = $this->startedAtMicrotime
                ? round((microtime(true) - $this->startedAtMicrotime) * 1000, 2)
                : null;

            $this->currentTrace->update([
                'status' => $status,
                'completed_at' => now(),
                'duration_ms' => $durationMs,
                'peak_memory_usage' => memory_get_peak_usage(true),
            ]);

            // Fire notification if configured and trace failed
            if ($status === 'error' && config('trace-replay.notifications.on_failure', false)) {
                try {
                    app(NotificationService::class)->notifyFailure($this->currentTrace->fresh(['steps']));
                } catch (Throwable) {
                }
            }
        } catch (Throwable $e) {
            $this->handleInternalError($e);
        } finally {
            $this->currentTrace = null;
            $this->lastStep = null;
            $this->stepBuffer = [];
            $this->stepStack = [];
            $this->stepCounter = 0;
            $this->pendingContext = [];
            $this->startedAtMicrotime = null;
            $this->traceParent = null;
            $this->traceDepth = 0;
            // Note: workspaceId and projectId persist across start/end if set via middleware/manually
            // but in Octane they should be reset if they depend on the request.
        }
    }

    public function setWorkspaceId(?string $id): void
    {
        $this->workspaceId = $id;
    }

    public function setProjectId(?string $id): void
    {
        $this->projectId = $id;
    }

    public function setTraceParent(?string $traceParent): void
    {
        $this->traceParent = $traceParent;
    }

    public function recordEvent($event): void
    {
        if (empty($this->stepStack)) {
            return;
        }

        // Add to all active steps (nested steps)
        foreach ($this->stepStack as &$frame) {
            $type = class_basename($event);

            if ($event instanceof CacheHit) {
                $frame['cache_hit_count']++;
                $frame['cache_calls'][] = ['type' => 'Hit', 'key' => $event->key, 'time' => microtime(true)];
            } elseif ($event instanceof CacheMissed) {
                $frame['cache_miss_count']++;
                $frame['cache_calls'][] = ['type' => 'Miss', 'key' => $event->key, 'time' => microtime(true)];
            } elseif ($event instanceof KeyWritten || $event instanceof KeyForgotten) {
                $frame['cache_calls'][] = ['type' => $type, 'key' => $event->key, 'time' => microtime(true)];
            } elseif ($event instanceof HttpRequestSending) {
                $frame['http_calls'][$event->request->url()] = [
                    'url' => $event->request->url(),
                    'method' => $event->request->method(),
                    'start' => microtime(true),
                ];
            } elseif ($event instanceof HttpResponseReceived) {
                if (isset($frame['http_calls'][$event->request->url()])) {
                    $frame['http_calls'][$event->request->url()]['status'] = $event->response->status();
                    $frame['http_calls'][$event->request->url()]['duration'] = round((microtime(true) - $frame['http_calls'][$event->request->url()]['start']) * 1000, 2);
                }
            } elseif ($event instanceof MessageSending || $event instanceof NotificationSending) {
                $frame['mail_calls'][] = [
                    'type' => $type,
                    'subject' => method_exists($event, 'message') ? $event->message?->getSubject() : null,
                    'to' => method_exists($event, 'message') ? array_keys($event->message?->getTo() ?? []) : null,
                    'time' => microtime(true),
                ];
            } elseif ($event instanceof MessageLogged) {
                $frame['log_calls'][] = [
                    'level' => $event->level,
                    'message' => $event->message,
                    'context' => $event->context,
                    'time' => microtime(true),
                ];
            }
        }
    }

    public function getCurrentTrace(): ?Trace
    {
        return $this->currentTrace;
    }

    // ── Internal Helpers ─────────────────────────────────────────────────────

    protected function persistStep(TraceStep $step): void
    {
        $stepData = $step->toArray();

        // Recommendation 28: Truncate oversized payloads to prevent DB bloat
        $maxSize = (int) config('trace-replay.max_payload_size', 65536);
        $keysToTruncate = ['request_payload', 'response_payload', 'state_snapshot', 'db_queries', 'cache_calls', 'http_calls', 'mail_calls'];

        foreach ($keysToTruncate as $key) {
            if (isset($stepData[$key]) && ! empty($stepData[$key])) {
                $encoded = json_encode($stepData[$key]);
                if (strlen($encoded) > $maxSize) {
                    $stepData[$key] = [
                        '_truncated' => true,
                        'original_size' => strlen($encoded),
                        'message' => "Payload truncated; exceeds {$maxSize} bytes.",
                    ];
                }
            }
        }

        if (config('trace-replay.queue.enabled')) {
            try {
                dispatch(new PersistTraceStepJob($stepData))
                    ->onConnection(config('trace-replay.queue.connection'))
                    ->onQueue(config('trace-replay.queue.queue'));
            } catch (Throwable $e) {
                $this->handleInternalError($e);
            }

            return;
        }

        if (! config('trace-replay.batch_persistence', true)) {
            try {
                $this->lastStep = TraceStep::create($stepData);
            } catch (Throwable $e) {
                $this->handleInternalError($e);
            }

            return;
        }

        // Buffer for batch insert at the end
        $this->stepBuffer[] = $stepData;
    }

    protected function flushStepBuffer(): void
    {
        if (empty($this->stepBuffer)) {
            return;
        }

        try {
            // Must manually json_encode array casts because insert() bypasses Eloquent casts.
            $jsonFields = ['request_payload', 'response_payload', 'state_snapshot', 'db_queries',
                'cache_calls', 'http_calls', 'mail_calls', 'log_calls', 'error_reason'];

            // All rows in a batch insert MUST have identical column sets.
            // Steps and checkpoints produce different subsets of columns, so we collect
            // the union of all keys and fill every row with null for missing ones.
            $now = now();
            $rows = [];
            foreach ($this->stepBuffer as $item) {
                $row = array_merge(['id' => (string) Str::uuid(),
                    'created_at' => $now, 'updated_at' => $now], $item);
                foreach ($jsonFields as $field) {
                    if (array_key_exists($field, $row) && (is_array($row[$field]) || is_object($row[$field]))) {
                        $row[$field] = json_encode($row[$field]);
                    }
                }
                $rows[] = $row;
            }

            // Determine the union of all column names across every row
            $allKeys = array_unique(array_merge(...array_map('array_keys', $rows)));

            // Columns with NOT NULL + integer/float defaults that cannot be null
            $intDefaults = [
                'cache_hit_count' => 0,
                'cache_miss_count' => 0,
                'db_query_count' => 0,
                'db_query_time_ms' => 0,
                'memory_usage' => 0,
                'step_order' => 0,
                'duration_ms' => 0,
            ];

            // Normalise every row so it has every key (null for absent optional columns,
            // integer default for absent NOT NULL columns)
            $data = array_map(function (array $row) use ($allKeys, $intDefaults): array {
                foreach ($allKeys as $key) {
                    if (! array_key_exists($key, $row)) {
                        $row[$key] = $intDefaults[$key] ?? null;
                    }
                }

                return $row;
            }, $rows);

            TraceStep::insert($data);
            $this->stepBuffer = [];
        } catch (Throwable $e) {
            $this->handleInternalError($e);
            $this->stepBuffer = []; // clear to avoid repeated failures on next end()
        }
    }

    protected function determineProjectId(): ?string
    {
        return config('trace-replay.project_id');
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
