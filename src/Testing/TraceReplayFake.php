<?php

namespace TraceReplay\Testing;

use PHPUnit\Framework\Assert as PHPUnit;
use TraceReplay\Models\Trace;

class TraceReplayFake
{
    /** @var array<int, array<string, mixed>> */
    protected array $recordedTraces = [];

    /** @var array<string, mixed>|null */
    protected ?array $currentTrace = null;

    protected ?string $workspaceId = null;

    protected ?string $projectId = null;

    protected ?string $traceParent = null;

    public function start(string $name, array $tags = [], bool $forceSample = false): Trace
    {
        $this->currentTrace = [
            'name' => $name,
            'tags' => $tags,
            'steps' => [],
            'status' => 'processing',
        ];

        // Store the index so we can update the recorded entry as steps/status change.
        // We do NOT store a reference here because setting $this->currentTrace = null in end()
        // would also null out the recorded entry via the reference.
        $this->recordedTraces[] = $this->currentTrace;
        $traceIndex = array_key_last($this->recordedTraces);
        $this->currentTrace['_recorded_index'] = $traceIndex;

        // Return a dummy model to keep compatibility with existing code
        return new Trace([
            'id' => '00000000-0000-0000-0000-000000000000',
            'name' => $name,
            'tags' => $tags,
        ]);
    }

    public function step(string $label, callable $callback, array $extra = []): mixed
    {
        if ($this->currentTrace) {
            $entry = ['label' => $label, 'extra' => $extra];
            $this->currentTrace['steps'][] = $entry;
            $this->recordedTraces[$this->currentTrace['_recorded_index']]['steps'][] = $entry;
        }

        return $callback();
    }

    public function measure(string $label, callable $callback, array $extra = []): mixed
    {
        return $this->step($label, $callback, $extra);
    }

    public function checkpoint(string $label, array $state = []): void
    {
        if ($this->currentTrace) {
            $entry = ['label' => $label, 'extra' => $state, 'type' => 'checkpoint'];
            $this->currentTrace['steps'][] = $entry;
            $this->recordedTraces[$this->currentTrace['_recorded_index']]['steps'][] = $entry;
        }
    }

    public function context(array $data): static
    {
        return $this;
    }

    public function end(string $status = 'success'): void
    {
        if ($this->currentTrace) {
            $this->recordedTraces[$this->currentTrace['_recorded_index']]['status'] = $status;
            $this->currentTrace['status'] = $status;
        }
        $this->currentTrace = null;
    }

    public function tag(string $key, mixed $value): static
    {
        return $this;
    }

    // ── Passthrough methods (parity with TraceReplayManager) ─────────────────

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

    public function getCurrentTrace(): ?Trace
    {
        return null; // Fake never creates real Eloquent models; callers may assert on null safely
    }

    public function captureResponseOnLastStep(array $responsePayload, int $httpStatus = 200): void
    {
        // No-op in fake — kept for interface parity
    }

    public function recordEvent(mixed $event): void
    {
        // No-op in fake
    }

    // ── Assertions ───────────────────────────────────────────────────────────

    public function assertTraceStarted(string $name): void
    {
        PHPUnit::assertTrue(
            collect($this->recordedTraces)->contains('name', $name),
            "The expected trace [{$name}] was not started."
        );
    }

    public function assertStepRecorded(string $label): void
    {
        $recorded = collect($this->recordedTraces)
            ->pluck('steps')
            ->flatten(1)
            ->contains('label', $label);

        PHPUnit::assertTrue(
            $recorded,
            "The expected step [{$label}] was not recorded."
        );
    }

    public function assertStepCount(int $count, ?string $traceName = null): void
    {
        $actualCount = $traceName
            ? collect($this->recordedTraces)->where('name', $traceName)->pluck('steps')->flatten(1)->count()
            : collect($this->recordedTraces)->pluck('steps')->flatten(1)->count();

        PHPUnit::assertEquals(
            $count,
            $actualCount,
            "Expected {$count} steps, but found {$actualCount}."
        );
    }

    public function assertTraceEnded(string $status = 'success'): void
    {
        $ended = collect($this->recordedTraces)->contains('status', $status);

        PHPUnit::assertTrue(
            $ended,
            "No trace with status [{$status}] was found."
        );
    }

    public function assertCheckpointRecorded(string $label): void
    {
        $recorded = collect($this->recordedTraces)
            ->pluck('steps')
            ->flatten(1)
            ->where('type', 'checkpoint')
            ->contains('label', $label);

        PHPUnit::assertTrue(
            $recorded,
            "The expected checkpoint [{$label}] was not recorded."
        );
    }

    public function assertNoTraceStarted(): void
    {
        PHPUnit::assertEmpty(
            $this->recordedTraces,
            'Expected no traces to be started, but '.count($this->recordedTraces).' trace(s) were recorded.'
        );
    }

    public function assertTraceCount(int $count): void
    {
        $actual = count($this->recordedTraces);

        PHPUnit::assertEquals(
            $count,
            $actual,
            "Expected {$count} trace(s) to be started, but found {$actual}."
        );
    }
}
