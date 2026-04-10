<?php

namespace TraceReplay;

use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyForgotten;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use TraceReplay\Console\Commands\ExportTraceCommand;
use TraceReplay\Console\Commands\PruneTracesCommand;
use TraceReplay\Facades\TraceReplay;
use TraceReplay\Listeners\CommandTraceListener;
use TraceReplay\Listeners\JobTraceListener;
use TraceReplay\Services\Ai\AiDriverInterface;
use TraceReplay\Services\Ai\Drivers\AnthropicDriver;
use TraceReplay\Services\Ai\Drivers\OllamaDriver;
use TraceReplay\Services\Ai\Drivers\OpenAiDriver;
use TraceReplay\Services\AiPromptService;
use TraceReplay\Services\NotificationService;
use TraceReplay\Services\PayloadMasker;
use TraceReplay\Services\ReplayService;
use TraceReplay\View\Components\TraceBar;

class TraceReplayServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/trace-replay.php', 'trace-replay');

        $this->app->scoped('trace-replay', fn ($app) => new TraceReplayManager($app));

        $this->app->singleton(PayloadMasker::class);
        $this->app->singleton(AiDriverInterface::class, function ($app) {
            $driver = config('trace-replay.ai.driver', 'openai');

            return match ($driver) {
                'anthropic' => new AnthropicDriver,
                'ollama' => new OllamaDriver,
                default => new OpenAiDriver,
            };
        });
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
                __DIR__.'/../config/trace-replay.php' => config_path('trace-replay.php'),
            ], 'trace-replay-config');

            $this->publishes([
                __DIR__.'/../database/migrations/' => database_path('migrations'),
            ], 'trace-replay-migrations');

            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/trace-replay'),
            ], 'trace-replay-views');

            $this->commands([
                PruneTracesCommand::class,
                ExportTraceCommand::class,
            ]);
        }

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'trace-replay');
        $this->loadViewComponentsAs('trace-replay', [
            TraceBar::class,
        ]);

        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->loadRoutesFrom(__DIR__.'/../routes/api.php');

        // Auto-trace queue jobs
        if (config('trace-replay.auto_trace.jobs', true)) {
            Event::listen(JobProcessing::class, fn (JobProcessing $e) => $this->app->make(JobTraceListener::class)->onJobProcessing($e));
            Event::listen(JobProcessed::class, fn (JobProcessed $e) => $this->app->make(JobTraceListener::class)->onJobProcessed($e));
            Event::listen(JobFailed::class, fn (JobFailed $e) => $this->app->make(JobTraceListener::class)->onJobFailed($e));
        }

        // Auto-trace artisan commands
        if (config('trace-replay.auto_trace.commands', false)) {
            Event::listen(CommandStarting::class, fn (CommandStarting $e) => $this->app->make(CommandTraceListener::class)->onCommandStarting($e));
            Event::listen(CommandFinished::class, fn (CommandFinished $e) => $this->app->make(CommandTraceListener::class)->onCommandFinished($e));
        }

        // Auto-trace Livewire components
        if (config('trace-replay.auto_trace.livewire', true) && class_exists(Livewire::class)) {
            try {
                Livewire::listen('component.hydrate', function ($component, $request) {
                    TraceReplay::checkpoint('Livewire Hydrate: '.get_class($component));
                });
                Livewire::listen('component.dehydrate', function ($component, $response) {
                    TraceReplay::checkpoint('Livewire Dehydrate: '.get_class($component));
                });
            } catch (\Throwable $e) {
                // Ignore if hook registration fails for specific versions
            }
        }

        // Register global collectors for active traces
        Event::listen([
            CacheHit::class,
            CacheMissed::class,
            KeyForgotten::class,
            KeyWritten::class,
            RequestSending::class,
            ResponseReceived::class,
            MessageSending::class,
            NotificationSending::class,
            MessageLogged::class,
        ], function ($event) {
            if ($this->app->bound('trace-replay')) {
                $this->app->make('trace-replay')->recordEvent($event);
            }
        });

        // Hook into Laravel's exception handler to capture exceptions for active traces
        $this->registerExceptionCapture();
    }

    /**
     * Register a reportable callback to capture exceptions in active traces.
     */
    protected function registerExceptionCapture(): void
    {
        if (! $this->app->bound(ExceptionHandler::class)) {
            return;
        }

        try {
            $handler = $this->app->make(ExceptionHandler::class);

            // Laravel 8+ uses reportable() method
            if (method_exists($handler, 'reportable')) {
                $handler->reportable(function (\Throwable $e) {
                    if ($this->app->bound('trace-replay')) {
                        $manager = $this->app->make('trace-replay');
                        if ($manager->getCurrentTrace()) {
                            $manager->captureException($e);
                        }
                    }

                    // Return false to allow other reporters to run
                    return false;
                });
            }
        } catch (\Throwable $e) {
            // Silently fail if exception handler is not compatible
        }
    }
}
