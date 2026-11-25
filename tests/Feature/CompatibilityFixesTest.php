<?php

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use TraceReplay\Facades\TraceReplay;
use TraceReplay\Http\Middleware\TraceReplayTenantMiddleware;
use TraceReplay\Models\Project;
use TraceReplay\Models\Trace;
use TraceReplay\Models\TraceStep;
use TraceReplay\Models\Workspace;
use TraceReplay\Services\Ai\AiDriverInterface;
use TraceReplay\Services\Ai\Drivers\AnthropicDriver;
use TraceReplay\Services\Ai\Drivers\Concerns\InteractsWithAiProvider;
use TraceReplay\Services\Ai\Drivers\OllamaDriver;
use TraceReplay\Services\Ai\Drivers\OpenAiDriver;
use TraceReplay\Services\AiPromptService;
use TraceReplay\Services\NotificationService;
use TraceReplay\TraceReplayServiceProvider;

final class TraceReplayLivewireCounterStub {}

function registerStubbedLivewireTracing(): void
{
    $provider = new TraceReplayServiceProvider(app());
    (fn () => $this->registerLivewireTracing())->call($provider);
}

function anthropicTextResponse(string $text = 'ok'): array
{
    return [
        'stop_reason' => 'end_turn',
        'content' => [['type' => 'text', 'text' => $text]],
    ];
}

// ── Exception reporting ─────────────────────────────────────────────────────

it('keeps Laravel default exception logging when a trace is active', function () {
    Log::spy();

    TraceReplay::start('Reported Exception');
    report(new RuntimeException('still logged'));

    Log::shouldHaveReceived('error')
        ->withArgs(fn ($message) => $message === 'still logged')
        ->once();

    expect(TraceReplay::getCurrentTrace()->fresh()->status)->toBe('error');
});

it('keeps Laravel default exception logging when no trace is active', function () {
    Log::spy();

    report(new RuntimeException('logged without trace'));

    Log::shouldHaveReceived('error')
        ->withArgs(fn ($message) => $message === 'logged without trace')
        ->once();
});

it('lets reportable callbacks registered after the package still run', function () {
    $ran = false;

    app(ExceptionHandler::class)->reportable(function (Throwable $e) use (&$ran): void {
        $ran = true;
    });

    report(new RuntimeException('second reporter'));

    expect($ran)->toBeTrue();
});

// ── Livewire 3+ hooks ───────────────────────────────────────────────────────

it('records Livewire 3+ hydrate and dehydrate hooks as checkpoints', function () {
    require_once __DIR__.'/../Stubs/Livewire.php';

    if (! method_exists(Livewire\Livewire::class, 'fire')) {
        $this->markTestSkipped('The real Livewire package is installed.');
    }

    Livewire\Livewire::$listeners = [];
    config(['trace-replay.auto_trace.livewire' => true]);
    registerStubbedLivewireTracing();

    expect(array_keys(Livewire\Livewire::$listeners))->toBe(['hydrate', 'dehydrate']);

    $component = new TraceReplayLivewireCounterStub;

    TraceReplay::start('Livewire Request');
    Livewire\Livewire::fire('hydrate', $component, [], null);
    Livewire\Livewire::fire('dehydrate', $component, null);
    $trace = TraceReplay::getCurrentTrace();
    TraceReplay::end();

    $labels = TraceStep::where('trace_id', $trace->id)->orderBy('step_order')->pluck('label')->all();

    expect($labels)->toBe([
        'Livewire Hydrate: TraceReplayLivewireCounterStub',
        'Livewire Dehydrate: TraceReplayLivewireCounterStub',
    ]);
});

it('labels anonymous Livewire components by their component name', function () {
    require_once __DIR__.'/../Stubs/Livewire.php';

    if (! method_exists(Livewire\Livewire::class, 'fire')) {
        $this->markTestSkipped('The real Livewire package is installed.');
    }

    Livewire\Livewire::$listeners = [];
    config(['trace-replay.auto_trace.livewire' => true]);
    registerStubbedLivewireTracing();

    // Livewire 4 single-file components compile to anonymous classes.
    $named = new class
    {
        public function getName(): string
        {
            return 'pages::counter';
        }
    };
    $broken = new class
    {
        public function getName(): string
        {
            throw new RuntimeException('not booted');
        }
    };

    TraceReplay::start('Livewire SFC Request');
    Livewire\Livewire::fire('hydrate', $named, [], null);
    Livewire\Livewire::fire('dehydrate', $broken, null);
    $trace = TraceReplay::getCurrentTrace();
    TraceReplay::end();

    $labels = TraceStep::where('trace_id', $trace->id)->orderBy('step_order')->pluck('label')->all();

    expect($labels)->toBe([
        'Livewire Hydrate: pages::counter',
        'Livewire Dehydrate: anonymous component',
    ]);
});

it('does not register Livewire hooks when Livewire auto-tracing is disabled', function () {
    require_once __DIR__.'/../Stubs/Livewire.php';

    if (! method_exists(Livewire\Livewire::class, 'fire')) {
        $this->markTestSkipped('The real Livewire package is installed.');
    }

    Livewire\Livewire::$listeners = [];
    config(['trace-replay.auto_trace.livewire' => false]);
    registerStubbedLivewireTracing();

    expect(Livewire\Livewire::$listeners)->toBe([]);
});

// ── Anthropic driver ────────────────────────────────────────────────────────

it('AnthropicDriver defaults to a current model and reads text after thinking blocks', function () {
    config([
        'trace-replay.ai.driver' => 'anthropic',
        'trace-replay.ai.api_key' => 'test-key',
        'trace-replay.ai.model' => null,
    ]);

    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'stop_reason' => 'end_turn',
            'content' => [
                ['type' => 'thinking', 'thinking' => '', 'signature' => 'sig'],
                ['type' => 'text', 'text' => 'Root cause: missing guard.'],
            ],
        ]),
    ]);

    $result = app(AnthropicDriver::class)->complete('debug this');

    expect($result)->toBe('Root cause: missing guard.');

    Http::assertSent(function (HttpRequest $request) {
        return $request->url() === 'https://api.anthropic.com/v1/messages'
            && $request['model'] === AnthropicDriver::DEFAULT_MODEL
            && $request['max_tokens'] === AnthropicDriver::THINKING_MODEL_MAX_TOKENS
            && $request['fallbacks'] === 'default'
            && $request->hasHeader('anthropic-beta', 'server-side-fallback-2026-07-01')
            && $request->hasHeader('x-api-key', 'test-key');
    });
});

it('AnthropicDriver treats the legacy gpt-4o config default as no model', function () {
    // Configs published before 1.3.2 carry env('TRACE_REPLAY_AI_MODEL', 'gpt-4o').
    config(['trace-replay.ai.api_key' => 'test-key', 'trace-replay.ai.model' => 'gpt-4o']);

    Http::fake(['api.anthropic.com/*' => Http::response(anthropicTextResponse())]);

    Log::spy();

    expect(app(AnthropicDriver::class)->complete('debug this'))->toBe('ok');

    Http::assertSent(fn (HttpRequest $request) => $request['model'] === AnthropicDriver::DEFAULT_MODEL);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn ($message, $context) => ($context['configured_model'] ?? null) === 'gpt-4o')
        ->once();
});

it('AnthropicDriver sends a pinned pre-Claude-5 model exactly as 1.3.1 did', function () {
    config(['trace-replay.ai.api_key' => 'test-key', 'trace-replay.ai.model' => 'claude-sonnet-4-5']);

    Http::fake(['api.anthropic.com/*' => Http::response(anthropicTextResponse())]);

    expect(app(AnthropicDriver::class)->complete('debug this'))->toBe('ok');

    Http::assertSent(function (HttpRequest $request) {
        return $request['model'] === 'claude-sonnet-4-5'
            && $request['max_tokens'] === AnthropicDriver::LEGACY_MODEL_MAX_TOKENS
            && ! array_key_exists('fallbacks', $request->data())
            && ! $request->hasHeader('anthropic-beta');
    });
});

it('AnthropicDriver gives pinned thinking models room to answer', function (string $model, bool $fallback) {
    config(['trace-replay.ai.api_key' => 'test-key', 'trace-replay.ai.model' => $model]);

    Http::fake(['api.anthropic.com/*' => Http::response(anthropicTextResponse())]);

    app(AnthropicDriver::class)->complete('debug this');

    Http::assertSent(function (HttpRequest $request) use ($model, $fallback) {
        return $request['model'] === $model
            && $request['max_tokens'] === AnthropicDriver::THINKING_MODEL_MAX_TOKENS
            && array_key_exists('fallbacks', $request->data()) === $fallback
            && $request->hasHeader('anthropic-beta') === $fallback;
    });
})->with([
    'default id pinned (README example)' => ['claude-opus-5-5', true],
    'sonnet 5.5' => ['claude-sonnet-5-5', true],
    'haiku 5.5 (no server-side fallback)' => ['claude-haiku-5-5', false],
]);

it('AnthropicDriver logs when thinking uses the whole output budget', function () {
    config(['trace-replay.ai.api_key' => 'test-key', 'trace-replay.ai.model' => null]);

    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'stop_reason' => 'max_tokens',
            'content' => [['type' => 'thinking', 'thinking' => '', 'signature' => 'sig']],
        ]),
    ]);

    Log::spy();

    expect(app(AnthropicDriver::class)->complete('debug this'))->toBeNull();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn ($message, $context) => ($context['reason'] ?? null) === 'max_tokens')
        ->once();
});

it('AnthropicDriver honours a configured max_tokens', function () {
    config([
        'trace-replay.ai.api_key' => 'test-key',
        'trace-replay.ai.model' => 'claude-sonnet-5-5',
        'trace-replay.ai.max_tokens' => '8000',
    ]);

    Http::fake(['api.anthropic.com/*' => Http::response(anthropicTextResponse())]);

    app(AnthropicDriver::class)->complete('debug this');

    Http::assertSent(fn (HttpRequest $request) => $request['max_tokens'] === 8000);
});

it('AnthropicDriver returns null for refusals', function () {
    config(['trace-replay.ai.api_key' => 'test-key']);

    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'stop_reason' => 'refusal',
            'content' => [],
        ]),
    ]);

    expect(app(AnthropicDriver::class)->complete('debug this'))->toBeNull();
});

it('AnthropicDriver keeps only the text produced after a fallback switch', function () {
    config(['trace-replay.ai.api_key' => 'test-key']);

    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'stop_reason' => 'end_turn',
            'content' => [
                ['type' => 'text', 'text' => 'partial'],
                ['type' => 'fallback', 'from' => ['model' => 'claude-opus-5-5'], 'to' => ['model' => 'claude-opus-4-8']],
                ['type' => 'text', 'text' => 'final answer'],
            ],
        ]),
    ]);

    expect(app(AnthropicDriver::class)->complete('debug this'))->toBe('final answer');
});

// ── OpenAI / Ollama drivers ─────────────────────────────────────────────────

it('OpenAiDriver still defaults to gpt-4o when no model is configured', function () {
    config([
        'trace-replay.ai.api_key' => 'test-key',
        'trace-replay.ai.model' => null,
    ]);

    Http::fake([
        'api.openai.com/*' => Http::response([
            'choices' => [['message' => ['content' => 'fix']]],
        ]),
    ]);

    expect(app(OpenAiDriver::class)->complete('debug this'))->toBe('fix');

    Http::assertSent(fn (HttpRequest $request) => $request['model'] === 'gpt-4o');
});

it('OllamaDriver uses its default endpoint and model when none are configured', function () {
    config([
        'trace-replay.ai.base_url' => null,
        'trace-replay.ai.model' => null,
    ]);

    Http::fake([
        'localhost:11434/*' => Http::response(['response' => 'local fix']),
    ]);

    expect(app(OllamaDriver::class)->complete('debug this'))->toBe('local fix');

    Http::assertSent(fn (HttpRequest $request) => $request->url() === OllamaDriver::DEFAULT_URL
        && $request['model'] === OllamaDriver::DEFAULT_MODEL);
});

it('OllamaDriver posts to a configured base_url unchanged', function (string $baseUrl) {
    config(['trace-replay.ai.base_url' => $baseUrl, 'trace-replay.ai.model' => 'llama3.2']);

    Http::fake(['*' => Http::response(['response' => 'ok'])]);

    expect(app(OllamaDriver::class)->complete('debug this'))->toBe('ok');

    Http::assertSent(fn (HttpRequest $request) => $request->url() === $baseUrl
        && $request['model'] === 'llama3.2');
})->with([
    'generate endpoint' => ['http://ollama.test:11434/api/generate'],
    'proxy at root' => ['http://proxy.test/'],
    'proxy with query' => ['http://proxy.test/generate?key=abc'],
]);

// ── Shared driver behaviour ─────────────────────────────────────────────────

it('AI drivers fall back to a 60 second timeout for missing or invalid values', function (mixed $configured, int $expected) {
    config(['trace-replay.ai.timeout' => $configured]);

    $driver = new class
    {
        use InteractsWithAiProvider;

        public function resolvedTimeout(): int
        {
            return $this->timeout();
        }
    };

    expect($driver->resolvedTimeout())->toBe($expected);
})->with([
    'missing (older published config)' => [null, 60],
    'empty env' => ['', 60],
    'non-numeric' => ['abc', 60],
    'zero' => [0, 60],
    'configured' => ['15', 15],
]);

it('AI drivers log the status of failed responses without the response body', function () {
    config(['trace-replay.ai.api_key' => 'test-key', 'trace-replay.ai.driver' => 'openai']);

    Http::fake([
        'api.openai.com/*' => Http::response([
            'error' => ['type' => 'invalid_request_error', 'message' => 'secret detail'],
        ], 404),
    ]);

    Log::spy();

    expect(app(OpenAiDriver::class)->complete('debug this'))->toBeNull();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn ($message, $context) => str_contains($message, 'openai')
            && $context === ['status' => 404, 'error_type' => 'invalid_request_error'])
        ->once();
});

it('AiPromptService returns null instead of failing when the AI provider is unreachable', function () {
    config(['trace-replay.ai.api_key' => 'test-key', 'trace-replay.ai.driver' => 'openai']);

    Http::fake(fn () => throw new ConnectionException('cURL error 28 for https://proxy.test/v1?token=leaky'));

    Log::spy();

    expect(app(AiPromptService::class)->callAi('debug this'))->toBeNull();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn ($message, $context) => ! str_contains($message.json_encode($context), 'leaky')
            && $context['exception'] === ConnectionException::class)
        ->once();
});

it('dashboard AI prompt endpoint still returns the prompt when the AI provider times out', function () {
    config(['trace-replay.ai.api_key' => 'test-key', 'trace-replay.ai.driver' => 'openai']);
    app()->forgetInstance(AiDriverInterface::class);
    app()->forgetInstance(AiPromptService::class);

    Http::fake(fn () => throw new ConnectionException('Connection timed out'));

    TraceReplay::start('AI Timeout');
    try {
        TraceReplay::step('Fail', fn () => throw new Exception('boom'));
    } catch (Exception) {
    }
    $trace = TraceReplay::getCurrentTrace();
    TraceReplay::end('error');

    $response = $this->postJson("/trace-replay/traces/{$trace->id}/ai-prompt");

    $response->assertOk()
        ->assertJsonPath('status', 'success')
        ->assertJsonPath('data.ai_response', null);

    expect($response->json('data.prompt'))->toContain('boom');
});

// ── Notifications ───────────────────────────────────────────────────────────

it('NotificationService links to the dashboard using the configured route prefix', function () {
    config([
        'app.url' => 'https://app.test/',
        'trace-replay.route_prefix' => '/ops/traces-ui/',
        'trace-replay.notifications.channels' => ['mail', 'slack'],
        'trace-replay.notifications.mail.to' => 'ops@example.com',
        'trace-replay.notifications.slack.webhook_url' => 'https://hooks.slack.test/webhook',
    ]);

    $trace = Trace::factory()->create(['status' => 'error']);
    $expectedUrl = "https://app.test/ops/traces-ui/traces/{$trace->id}";

    Mail::shouldReceive('raw')
        ->once()
        ->withArgs(fn (string $body) => str_contains($body, $expectedUrl));

    Http::fake(['*' => Http::response([], 200)]);

    app(NotificationService::class)->notifyFailure($trace);

    Http::assertSent(fn (HttpRequest $request) => collect($request['attachments'][0]['fields'])
        ->contains('value', $expectedUrl));
});

it('NotificationService keeps the default dashboard path', function () {
    config([
        'app.url' => 'https://app.test',
        'trace-replay.notifications.channels' => ['mail'],
        'trace-replay.notifications.mail.to' => 'ops@example.com',
    ]);

    $trace = Trace::factory()->create(['status' => 'error']);

    Mail::shouldReceive('raw')
        ->once()
        ->withArgs(fn (string $body) => str_contains($body, "https://app.test/trace-replay/traces/{$trace->id}"));

    app(NotificationService::class)->notifyFailure($trace);
});

// ── Middleware hardening ────────────────────────────────────────────────────

it('TraceReplayTenantMiddleware ignores array scope parameters instead of failing', function () {
    Route::get('/tenant-probe', fn () => 'ok')->middleware(TraceReplayTenantMiddleware::class);

    $this->get('/tenant-probe?tr_workspace[]=a&tr_project[]=b')->assertOk();
});

it('TraceReplayTenantMiddleware still applies string scope parameters', function () {
    $project = Project::create([
        'workspace_id' => Workspace::create(['name' => 'WS'])->id,
        'name' => 'Project',
    ]);

    Route::get('/tenant-probe', function () {
        $trace = TraceReplay::start('Tenant Probe', forceSample: true);

        return response()->json([
            'workspace_id' => $trace?->workspace_id,
            'project_id' => $trace?->project_id,
        ]);
    })->middleware(TraceReplayTenantMiddleware::class);

    $this->get("/tenant-probe?tr_workspace=ws-1&tr_project={$project->id}")
        ->assertOk()
        ->assertJsonPath('workspace_id', 'ws-1')
        ->assertJsonPath('project_id', $project->id);
});

it('TraceReplayAuthMiddleware trims configured IP addresses', function () {
    config(['trace-replay.allowed_ips' => ['10.0.0.1', ' 127.0.0.1 ']]);

    $this->get('/trace-replay')->assertOk();
});

it('TraceReplayAuthMiddleware still blocks IPs missing from a trimmed list', function () {
    config(['trace-replay.allowed_ips' => [' 10.0.0.1 ', '10.0.0.2']]);

    $this->get('/trace-replay')->assertForbidden();
});

it('TraceReplayAuthMiddleware keeps a whitespace-only allowlist closed', function () {
    config(['trace-replay.allowed_ips' => ['   ']]);

    $this->get('/trace-replay')->assertForbidden();
});

it('allowed_ips parsed from the environment matches trimmed entries and stays closed when blank', function (string $env, array $expected, int $status) {
    $previous = $_SERVER['TRACE_REPLAY_ALLOWED_IPS'] ?? null;
    $_SERVER['TRACE_REPLAY_ALLOWED_IPS'] = $env;

    try {
        $config = require __DIR__.'/../../config/trace-replay.php';
    } finally {
        if ($previous === null) {
            unset($_SERVER['TRACE_REPLAY_ALLOWED_IPS']);
        } else {
            $_SERVER['TRACE_REPLAY_ALLOWED_IPS'] = $previous;
        }
    }

    expect(array_values($config['allowed_ips']))->toBe($expected);

    config(['trace-replay.allowed_ips' => $config['allowed_ips']]);

    $this->get('/trace-replay')->assertStatus($status);
})->with([
    'comma and space separated' => ['10.0.0.1, 127.0.0.1', ['10.0.0.1', ' 127.0.0.1'], 200],
    'whitespace only' => ['   ', ['   '], 403],
]);

// ── Batch persistence ───────────────────────────────────────────────────────

it('batch persistence uses the model UUID generator for every step', function () {
    config(['trace-replay.batch_persistence' => true, 'trace-replay.queue.enabled' => false]);

    TraceReplay::start('Batch UUIDs');
    TraceReplay::step('One', fn () => 1);
    TraceReplay::checkpoint('Two');
    TraceReplay::step('Three', fn () => 3);
    $trace = TraceReplay::getCurrentTrace();
    TraceReplay::end();

    $ids = TraceStep::where('trace_id', $trace->id)->orderBy('step_order')->pluck('id');

    // Version nibble of the generator Eloquent uses (v7 on Laravel 12+).
    $version = ((string) (new TraceStep)->newUniqueId())[14];

    expect($ids)->toHaveCount(3)
        ->and($ids->unique())->toHaveCount(3)
        ->and($ids->every(fn ($id) => Str::isUuid($id) && $id[14] === $version))->toBeTrue();
});
