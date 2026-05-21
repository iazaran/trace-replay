<?php

namespace TraceReplay\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class DoctorCommand extends Command
{
    protected $signature = 'trace-replay:doctor';

    protected $description = 'Show TraceReplay configuration and production-readiness checks.';

    public function handle(): int
    {
        $this->components->info('TraceReplay diagnostics');

        $checks = [
            ['Tracing enabled', config('trace-replay.enabled') ? 'yes' : 'no', config('trace-replay.enabled')],
            ['Dashboard middleware', implode(', ', config('trace-replay.middleware', [])), $this->hasProtectedDashboard()],
            ['API token configured', config('trace-replay.api.token') ? 'yes' : 'no', (bool) config('trace-replay.api.token')],
            ['Sample rate', (string) config('trace-replay.sample_rate', 1.0), (float) config('trace-replay.sample_rate', 1.0) <= 1.0],
            ['Retention days', config('trace-replay.retention_days') ?? 'disabled', config('trace-replay.retention_days') !== null],
            ['DB query tracking', config('trace-replay.track_db_queries') ? 'enabled' : 'disabled', true],
            ['DB query bindings', config('trace-replay.track_db_query_bindings') ? 'captured' : 'masked', ! config('trace-replay.track_db_query_bindings')],
            ['Mutating replay', config('trace-replay.replay.allow_mutating_methods') ? 'allowed' : 'blocked', ! config('trace-replay.replay.allow_mutating_methods')],
            ['Replay override hosts', $this->replayHostsLabel(), true],
            ['API middleware', implode(', ', config('trace-replay.api.middleware', [])), $this->apiMiddlewareExists()],
            ['Trace tables', $this->hasTables() ? 'present' : 'missing', $this->hasTables()],
        ];

        foreach ($checks as [$label, $value, $healthy]) {
            $prefix = $healthy ? '<fg=green>OK</>' : '<fg=yellow>WARN</>';
            $this->line("{$prefix} {$label}: {$value}");
        }

        if (! $this->hasProtectedDashboard()) {
            $this->warn('Dashboard middleware should include auth, can:view-trace-replay, or another access-control middleware before production use.');
        }

        if (! config('trace-replay.api.token')) {
            $this->line('API routes are disabled until TRACE_REPLAY_API_TOKEN is set.');
        }

        if (! $this->apiMiddlewareExists()) {
            $this->warn("Configured API middleware includes 'api', but this application does not define an api middleware group. Set trace-replay.api.middleware to middleware that exists in your app, such as ['throttle:api'].");
        }

        return self::SUCCESS;
    }

    protected function hasProtectedDashboard(): bool
    {
        $middleware = config('trace-replay.middleware', []);

        return collect($middleware)->contains(fn (string $entry) => $entry === 'auth' || str_starts_with($entry, 'can:'));
    }

    protected function hasTables(): bool
    {
        try {
            return Schema::hasTable('tr_traces') && Schema::hasTable('tr_trace_steps');
        } catch (\Throwable) {
            return false;
        }
    }

    protected function replayHostsLabel(): string
    {
        $hosts = config('trace-replay.replay.allowed_hosts', []);

        return empty($hosts)
            ? 'original host only'
            : implode(', ', $hosts);
    }

    protected function apiMiddlewareExists(): bool
    {
        $middleware = config('trace-replay.api.middleware', []);

        if (! \in_array('api', $middleware, true)) {
            return true;
        }

        return array_key_exists('api', app('router')->getMiddlewareGroups());
    }
}
