<?php

namespace TraceReplay\Listeners;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use TraceReplay\Facades\TraceReplay;

class CommandTraceListener
{
    public function onCommandStarting(CommandStarting $event): void
    {
        if ($this->shouldIgnore($event->command)) {
            return;
        }

        TraceReplay::start("Artisan: {$event->command}", [
            'command' => $event->command,
            'arguments' => (string) $event->input,
        ]);

        TraceReplay::checkpoint('Command Started');
    }

    public function onCommandFinished(CommandFinished $event): void
    {
        if ($this->shouldIgnore($event->command)) {
            return;
        }

        if (! TraceReplay::getCurrentTrace()) {
            return;
        }

        $status = $event->exitCode === 0 ? 'success' : 'error';

        TraceReplay::checkpoint('Command Finished', [
            'exit_code' => $event->exitCode,
        ]);

        TraceReplay::end($status);
    }

    protected function shouldIgnore(?string $command): bool
    {
        if (empty($command)) {
            return true;
        }

        $excluded = config('trace-replay.auto_trace.exclude_commands', []);

        return \in_array($command, $excluded, true);
    }
}
