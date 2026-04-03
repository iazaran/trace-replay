<?php

namespace TraceReplay\Listeners;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use TraceReplay\Facades\TraceReplay;

class CommandTraceListener
{
    public function onCommandStarting(CommandStarting $event): void
    {
        if (! config('tracereplay.auto_trace.commands', false)) {
            return;
        }

        // $command is ?string in some Laravel versions (e.g. when a command is anonymous)
        if (empty($event->command)) {
            return;
        }

        $excluded = config('tracereplay.auto_trace.exclude_commands', []);
        if (\in_array($event->command, $excluded, true)) {
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
        if (! config('tracereplay.auto_trace.commands', false)) {
            return;
        }

        if (empty($event->command)) {
            return;
        }

        $excluded = config('tracereplay.auto_trace.exclude_commands', []);
        if (\in_array($event->command, $excluded, true)) {
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
}
