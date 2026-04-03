<?php

namespace TraceReplay;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use TraceReplay\Console\Commands\ExportTraceCommand;
use TraceReplay\Console\Commands\PruneTracesCommand;
use TraceReplay\Listeners\CommandTraceListener;
use TraceReplay\Listeners\JobTraceListener;
use TraceReplay\Services\AiPromptService;
use TraceReplay\Services\NotificationService;
use TraceReplay\Services\PayloadMasker;
use TraceReplay\Services\ReplayService;
use TraceReplay\View\Components\TraceBar;

class TraceReplayServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/tracereplay.php', 'tracereplay');

        $this->app->singleton('tracereplay', fn ($app) => new TraceReplayManager($app));

        $this->app->singleton(PayloadMasker::class);
        $this->app->singleton(AiPromptService::class);
        $this->app->singleton(NotificationService::class);
        $this->app->singleton(ReplayService::class, fn ($app) => new ReplayService(
            $app->make(PayloadMasker::class)
        ));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/tracereplay.php' => config_path('tracereplay.php'),
            ], 'tracereplay-config');

            $this->publishes([
                __DIR__.'/../database/migrations/' => database_path('migrations'),
            ], 'tracereplay-migrations');

            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/tracereplay'),
            ], 'tracereplay-views');

            $this->commands([
                PruneTracesCommand::class,
                ExportTraceCommand::class,
            ]);
        }

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'tracereplay');
        $this->loadViewComponentsAs('tracereplay', [
            TraceBar::class,
        ]);

        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->loadRoutesFrom(__DIR__.'/../routes/api.php');

        // Auto-trace queue jobs
        if (config('tracereplay.auto_trace.jobs', true)) {
            Event::listen(JobProcessing::class, fn (JobProcessing $e) => $this->app->make(JobTraceListener::class)->onJobProcessing($e));
            Event::listen(JobProcessed::class, fn (JobProcessed $e) => $this->app->make(JobTraceListener::class)->onJobProcessed($e));
            Event::listen(JobFailed::class, fn (JobFailed $e) => $this->app->make(JobTraceListener::class)->onJobFailed($e));
        }

        // Auto-trace artisan commands
        if (config('tracereplay.auto_trace.commands', false)) {
            Event::listen(CommandStarting::class, fn (CommandStarting $e) => $this->app->make(CommandTraceListener::class)->onCommandStarting($e));
            Event::listen(CommandFinished::class, fn (CommandFinished $e) => $this->app->make(CommandTraceListener::class)->onCommandFinished($e));
        }
    }
}
