<?php

namespace TraceReplay\Console\Commands;

use Illuminate\Console\Command;

class InstallCommand extends Command
{
    protected $signature = 'trace-replay:install
                            {--force : Overwrite existing published config}
                            {--migrate : Run pending migrations after publishing config}';

    protected $description = 'Publish TraceReplay config and show the next setup steps.';

    public function handle(): int
    {
        $this->info('Publishing TraceReplay configuration...');

        $this->call('vendor:publish', [
            '--tag' => 'trace-replay-config',
            '--force' => (bool) $this->option('force'),
        ]);

        if ($this->option('migrate')) {
            $this->info('Running migrations...');
            $this->call('migrate');
        }

        $this->newLine();
        $this->info('TraceReplay is installed.');
        $this->line('Dashboard: /trace-replay');
        $this->line('Recommended next step: add TraceReplay\\Http\\Middleware\\TraceMiddleware to your web middleware stack.');
        $this->line('For production, keep dashboard middleware protected with auth or a can:view-trace-replay gate.');

        return self::SUCCESS;
    }
}
