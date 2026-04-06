<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use TraceReplay\Facades\TraceReplay;
use TraceReplay\Models\Project;
use TraceReplay\Models\Trace;
use TraceReplay\Models\TraceStep;
use TraceReplay\Models\Workspace;
use TraceReplay\Services\AiPromptService;
use TraceReplay\Services\NotificationService;
use TraceReplay\Services\PayloadMasker;
use TraceReplay\TraceReplayManager;
use TraceReplay\View\Components\TraceBar;

// ── Core Lifecycle ────────────────────────────────────────────────────────────

it('can start and end a trace', function () {
    $trace = TraceReplay::start('Login Process');

    expect($trace)->toBeInstanceOf(Trace::class)
        ->and($trace->status)->toBe('processing')
        ->and($trace->name)->toBe('Login Process');

    TraceReplay::end('success');

    $fresh = $trace->fresh();
    expect($fresh->status)->toBe('success')
        ->and($fresh->completed_at)->not->toBeNull()
        ->and($fresh->duration_ms)->toBeFloat(); // precision may be tiny negative due to clock resolution
});

it('returns null from start() when tracing is disabled', function () {
    config(['trace-replay.enabled' => false]);

    $trace = TraceReplay::start('Disabled Trace');

    expect($trace)->toBeNull();
});

it('marks trace as error when ended with error status', function () {
    $trace = TraceReplay::start('Failing Process');
    TraceReplay::end('error');

    expect($trace->fresh()->status)->toBe('error');
});

it('getCurrentTrace() returns the active trace', function () {
    $trace = TraceReplay::start('Active');
    expect(TraceReplay::getCurrentTrace())->toBe($trace);

    TraceReplay::end();
    expect(TraceReplay::getCurrentTrace())->toBeNull();
});

// ── Steps ─────────────────────────────────────────────────────────────────────

it('can record a step and returns the callback result', function () {
    TraceReplay::start('Booking');

    $result = TraceReplay::step('Validate Request', fn () => 'validated');

    expect($result)->toBe('validated');

    $trace = TraceReplay::getCurrentTrace();
    expect($trace->steps()->count())->toBe(1)
        ->and($trace->steps()->first()->label)->toBe('Validate Request')
        ->and($trace->steps()->first()->status)->toBe('success');
});

it('records step order correctly', function () {
    TraceReplay::start('Multi-Step');

    TraceReplay::step('Step A', fn () => 1);
    TraceReplay::step('Step B', fn () => 2);
    TraceReplay::step('Step C', fn () => 3);

    $steps = TraceReplay::getCurrentTrace()->steps()->orderBy('step_order')->pluck('label')->all();

    expect($steps)->toBe(['Step A', 'Step B', 'Step C']);
});

it('records error status when step throws an exception', function () {
    TraceReplay::start('Error Step Test');

    try {
        TraceReplay::step('Failing Step', function () {
            throw new RuntimeException('Intentional error');
        });
    } catch (RuntimeException) {
        // expected
    }

    $step = TraceReplay::getCurrentTrace()->steps()->first();

    expect($step->status)->toBe('error')
        ->and($step->error_reason)->toContain('Intentional error');
});

it('measure() is an alias for step()', function () {
    TraceReplay::start('Measure Test');

    $result = TraceReplay::measure('Measured Op', fn () => 42);

    expect($result)->toBe(42)
        ->and(TraceReplay::getCurrentTrace()->steps()->count())->toBe(1);
});

// ── Checkpoints ───────────────────────────────────────────────────────────────

it('can record a checkpoint', function () {
    TraceReplay::start('Checkpoint Test');

    TraceReplay::checkpoint('After Validation', ['user_id' => 5]);

    $step = TraceReplay::getCurrentTrace()->steps()->first();

    expect($step->label)->toBe('After Validation')
        ->and($step->type)->toBe('checkpoint')
        ->and($step->state_snapshot)->toMatchArray(['user_id' => 5]);
});

// ── Context ───────────────────────────────────────────────────────────────────

it('context() merges into the next step state_snapshot', function () {
    TraceReplay::start('Context Test');
    TraceReplay::context(['tenant' => 'acme', 'tier' => 'pro']);

    TraceReplay::step('Do Work', fn () => null);

    $snapshot = TraceReplay::getCurrentTrace()->steps()->first()->state_snapshot;

    expect($snapshot)->toMatchArray(['tenant' => 'acme', 'tier' => 'pro']);
});

// ── Model Scopes ──────────────────────────────────────────────────────────────

it('Trace::failed() scope filters by error status', function () {
    Trace::factory()->create(['status' => 'success']);
    Trace::factory()->create(['status' => 'error']);

    expect(Trace::failed()->count())->toBe(1);
});

it('Trace::successful() scope filters by success status', function () {
    Trace::factory()->create(['status' => 'success']);
    Trace::factory()->create(['status' => 'error']);

    expect(Trace::successful()->count())->toBe(1);
});

it('Trace::search() scope searches by name', function () {
    Trace::factory()->create(['name' => 'Login Flow']);
    Trace::factory()->create(['name' => 'Checkout Process']);

    expect(Trace::search('login')->count())->toBe(1);
});

// ── Accessors ─────────────────────────────────────────────────────────────────

it('error_step accessor returns the first error step', function () {
    TraceReplay::start('Error Accessor Test');
    TraceReplay::step('OK Step', fn () => null);

    try {
        TraceReplay::step('Bad Step', fn () => throw new Exception('boom'));
    } catch (Exception) {
    }

    $trace = TraceReplay::getCurrentTrace();
    $errorStep = $trace->error_step;

    expect($errorStep)->not->toBeNull()
        ->and($errorStep->label)->toBe('Bad Step');
});

it('total_db_queries accessor sums step db_query_count', function () {
    TraceReplay::start('DB Count Test');
    TraceReplay::step('Step', fn () => null);

    $trace = TraceReplay::getCurrentTrace();
    $trace->steps()->first()->update(['db_query_count' => 3]);

    expect($trace->total_db_queries)->toBe(3);
});

// ── PayloadMasker ─────────────────────────────────────────────────────────────

it('PayloadMasker masks configured sensitive fields', function () {
    config(['trace-replay.mask_fields' => ['password', 'token']]);

    $masker = new PayloadMasker;
    $result = $masker->mask([
        'username' => 'alice',
        'password' => 'supersecret',
        'token' => 'abc123',
        'data' => ['token' => 'nested_token'],
    ]);

    expect($result['username'])->toBe('alice')
        ->and($result['password'])->toBe('********')
        ->and($result['token'])->toBe('********')
        ->and($result['data']['token'])->toBe('********');
});

// ── AiPromptService ───────────────────────────────────────────────────────────

it('AiPromptService generates a prompt for a failed trace', function () {
    TraceReplay::start('AI Prompt Test');

    try {
        TraceReplay::step('Broken Step', fn () => throw new Exception('DB connection failed'));
    } catch (Exception) {
    }

    TraceReplay::end('error');

    $trace = Trace::latest()->first();
    $prompt = app(AiPromptService::class)->generateFixPrompt($trace->load('steps'));

    expect($prompt)->toContain('Broken Step')
        ->and($prompt)->toContain('DB connection failed')
        ->and($prompt)->toContain('Root Cause');
});

it('AiPromptService returns a no-error message for successful traces', function () {
    TraceReplay::start('Success Trace');
    TraceReplay::end('success');

    $trace = Trace::latest()->first();
    $prompt = app(AiPromptService::class)->generateFixPrompt($trace->load('steps'));

    expect($prompt)->toContain('successfully with no errors recorded');
});

// ── Artisan Commands ──────────────────────────────────────────────────────────

it('trace-replay:prune deletes old traces', function () {
    Trace::factory()->create(['started_at' => now()->subDays(60)]);
    Trace::factory()->create(['started_at' => now()->subDays(60)]);
    Trace::factory()->create(['started_at' => now()->subDays(1)]);

    $this->artisan('trace-replay:prune', ['--days' => 30])
        ->expectsOutput('Deleted 2 trace(s) older than 30 day(s).')
        ->assertExitCode(0);

    expect(Trace::count())->toBe(1);
});

it('trace-replay:prune dry-run does not delete traces', function () {
    Trace::factory()->create(['started_at' => now()->subDays(60)]);

    $this->artisan('trace-replay:prune', ['--days' => 30, '--dry-run' => true])
        ->assertExitCode(0);

    expect(Trace::count())->toBe(1);
});

it('trace-replay:export outputs JSON for a trace', function () {
    $trace = Trace::factory()->create(['name' => 'Exportable Trace']);

    $this->artisan('trace-replay:export', ['id' => $trace->id, '--format' => 'json'])
        ->assertExitCode(0);
});

// ── Export CSV ────────────────────────────────────────────────────────────────

it('trace-replay:export outputs CSV for a trace', function () {
    $trace = Trace::factory()->create(['name' => 'CSV, with "commas"']);

    $this->artisan('trace-replay:export', ['id' => $trace->id, '--format' => 'csv'])
        ->assertExitCode(0);
});

it('trace-replay:export rejects unsupported format', function () {
    $trace = Trace::factory()->create();

    $this->artisan('trace-replay:export', ['id' => $trace->id, '--format' => 'xml'])
        ->assertExitCode(1);
});

it('trace-replay:export returns failure for nonexistent trace', function () {
    $this->artisan('trace-replay:export', ['id' => 'nonexistent-id'])
        ->assertExitCode(1);
});

it('trace-replay:export filters by status', function () {
    Trace::factory()->create(['status' => 'success']);
    Trace::factory()->create(['status' => 'error']);

    $this->artisan('trace-replay:export', ['--status' => 'error', '--format' => 'json'])
        ->assertExitCode(0);
});

// ── Prune Command Extra ──────────────────────────────────────────────────────

it('trace-replay:prune rejects zero days', function () {
    $this->artisan('trace-replay:prune', ['--days' => 0])
        ->assertExitCode(1);
});

it('trace-replay:prune filters by status', function () {
    Trace::factory()->create(['started_at' => now()->subDays(60), 'status' => 'success']);
    Trace::factory()->create(['started_at' => now()->subDays(60), 'status' => 'error']);

    $this->artisan('trace-replay:prune', ['--days' => 30, '--status' => 'success'])
        ->assertExitCode(0);

    expect(Trace::count())->toBe(1)
        ->and(Trace::first()->status)->toBe('error');
});

it('trace-replay:prune shows no-op when no traces match', function () {
    $this->artisan('trace-replay:prune', ['--days' => 30])
        ->expectsOutput('No traces found matching the criteria.')
        ->assertExitCode(0);
});

// ── Dashboard Controller ─────────────────────────────────────────────────────

it('dashboard index page loads', function () {
    Trace::factory()->count(3)->create();

    $response = $this->get('/trace-replay');

    $response->assertOk();
    $response->assertSee('Traces');
});

it('dashboard index filters by status', function () {
    Trace::factory()->create(['status' => 'error', 'name' => 'Error Trace']);
    Trace::factory()->create(['status' => 'success', 'name' => 'Good Trace']);

    $response = $this->get('/trace-replay?status=error');

    $response->assertOk();
    $response->assertSee('Error Trace');
});

it('dashboard index search works', function () {
    Trace::factory()->create(['name' => 'Login Flow']);
    Trace::factory()->create(['name' => 'Checkout Process']);

    $response = $this->get('/trace-replay?search=Login');

    $response->assertOk();
    $response->assertSee('Login Flow');
});

it('dashboard show page loads for a valid trace', function () {
    $trace = Trace::factory()->create(['name' => 'Detail Test']);

    $response = $this->get("/trace-replay/traces/{$trace->id}");

    $response->assertOk();
    $response->assertSee('Detail Test');
});

it('dashboard show returns 404 for nonexistent trace', function () {
    $response = $this->get('/trace-replay/traces/nonexistent-uuid');

    $response->assertNotFound();
});

it('dashboard stats endpoint returns JSON', function () {
    Trace::factory()->create(['status' => 'success', 'duration_ms' => 100]);
    Trace::factory()->create(['status' => 'error', 'duration_ms' => 200]);

    $response = $this->getJson('/trace-replay/stats');

    $response->assertOk();
    $response->assertJsonStructure(['total', 'success', 'failed', 'today', 'failure_rate', 'avg_duration', 'slowest']);
    $response->assertJson(['total' => 2, 'success' => 1, 'failed' => 1]);
});

it('dashboard export downloads JSON file', function () {
    $trace = Trace::factory()->create(['name' => 'Export Me']);

    $response = $this->get("/trace-replay/traces/{$trace->id}/export");

    $response->assertOk();
    $response->assertHeader('Content-Type', 'application/json');
    $response->assertHeader('Content-Disposition');
});

// ── MCP API Controller ───────────────────────────────────────────────────────

it('MCP list traces returns paginated results', function () {
    Trace::factory()->count(3)->create();

    $response = $this->getJson('/api/trace-replay/mcp/traces');

    $response->assertOk();
    $response->assertJsonPath('status', 'success');
});

it('MCP list traces filters by error', function () {
    Trace::factory()->create(['status' => 'success']);
    Trace::factory()->create(['status' => 'error']);

    $response = $this->getJson('/api/trace-replay/mcp/traces?filter_by_error=1');

    $response->assertOk();
    $response->assertJsonPath('data.total', 1);
});

it('MCP get context returns trace details', function () {
    $trace = Trace::factory()->create(['status' => 'success']);

    $response = $this->getJson("/api/trace-replay/mcp/traces/{$trace->id}/context");

    $response->assertOk();
    $response->assertJsonPath('status', 'success');
    $response->assertJsonStructure(['data' => ['trace', 'completion_percentage', 'total_duration', 'error_step']]);
});

it('MCP generate fix prompt returns prompt', function () {
    TraceReplay::start('MCP Prompt Test');
    try {
        TraceReplay::step('Fail', fn () => throw new Exception('mcp error'));
    } catch (Exception) {
    }
    TraceReplay::end('error');

    $trace = Trace::latest()->first();

    $response = $this->getJson("/api/trace-replay/mcp/traces/{$trace->id}/fix-prompt");

    $response->assertOk();
    $response->assertJsonPath('status', 'success');
});

it('MCP RPC list_traces method works', function () {
    Trace::factory()->count(2)->create();

    $response = $this->postJson('/api/trace-replay/mcp', [
        'method' => 'list_traces',
        'params' => [],
        'id' => 1,
    ]);

    $response->assertOk();
    $response->assertJsonPath('jsonrpc', '2.0');
    $response->assertJsonPath('id', 1);
});

it('MCP RPC returns error for unknown method', function () {
    $response = $this->postJson('/api/trace-replay/mcp', [
        'method' => 'unknown_method',
        'params' => [],
        'id' => 42,
    ]);

    $response->assertOk();
    $response->assertJsonPath('jsonrpc', '2.0');
    $response->assertJsonPath('error.code', -32601);
    $response->assertJsonPath('id', 42);
});

it('MCP RPC get_trace_context method works', function () {
    $trace = Trace::factory()->create(['status' => 'error']);

    $response = $this->postJson('/api/trace-replay/mcp', [
        'method' => 'get_trace_context',
        'params' => ['trace_id' => $trace->id],
        'id' => 2,
    ]);

    $response->assertOk();
    $response->assertJsonPath('jsonrpc', '2.0');
});

it('MCP RPC generate_fix_prompt method works', function () {
    TraceReplay::start('RPC Prompt');
    try {
        TraceReplay::step('Err', fn () => throw new Exception('rpc fail'));
    } catch (Exception) {
    }
    TraceReplay::end('error');

    $trace = Trace::latest()->first();

    $response = $this->postJson('/api/trace-replay/mcp', [
        'method' => 'generate_fix_prompt',
        'params' => ['trace_id' => $trace->id],
        'id' => 3,
    ]);

    $response->assertOk();
    $response->assertJsonPath('jsonrpc', '2.0');
});

// ── TraceMiddleware ──────────────────────────────────────────────────────────

it('TraceMiddleware skips trace-replay dashboard routes', function () {
    // Dashboard route should not create a trace
    $this->get('/trace-replay');

    // The middleware skips routes starting with 'trace-replay'
    // Traces created should only be for the dashboard itself, not from middleware
    // Since dashboard creates no traces itself, count should be 0 from middleware
    expect(Trace::where('name', 'like', 'HTTP GET /trace-replay%')->count())->toBe(0);
});

it('TraceMiddleware respects disabled config', function () {
    config(['trace-replay.enabled' => false]);

    $this->get('/trace-replay');

    expect(Trace::count())->toBe(0);
});

// ── Auth Middleware ──────────────────────────────────────────────────────────

it('TraceReplayAuthMiddleware allows access when no IPs configured', function () {
    config(['trace-replay.allowed_ips' => []]);

    $response = $this->get('/trace-replay');

    $response->assertOk();
});

it('TraceReplayAuthMiddleware blocks access from unauthorized IPs', function () {
    config(['trace-replay.allowed_ips' => ['192.168.1.100']]);

    $response = $this->get('/trace-replay');

    $response->assertForbidden();
});

// ── captureResponseOnLastStep ────────────────────────────────────────────────

it('captureResponseOnLastStep attaches response to last step', function () {
    TraceReplay::start('Response Capture Test');
    TraceReplay::step('Some Work', fn () => null);

    TraceReplay::captureResponseOnLastStep(['status' => 200, 'body' => ['ok' => true]], 200);

    $step = TraceReplay::getCurrentTrace()->steps()->orderBy('step_order', 'desc')->first();

    expect($step->response_payload)->toMatchArray(['status' => 200, 'body' => ['ok' => true]]);
});

it('captureResponseOnLastStep does nothing without active trace', function () {
    // Should not throw
    TraceReplay::captureResponseOnLastStep(['data' => 'test']);

    expect(true)->toBeTrue();
});

// ── Context Accumulation ─────────────────────────────────────────────────────

it('context() accumulates across multiple calls', function () {
    TraceReplay::start('Multi Context');
    TraceReplay::context(['a' => 1]);
    TraceReplay::context(['b' => 2]);

    TraceReplay::step('Work', fn () => null);

    $snapshot = TraceReplay::getCurrentTrace()->steps()->first()->state_snapshot;

    expect($snapshot)->toMatchArray(['a' => 1, 'b' => 2]);
});

it('context() resets after step consumes it', function () {
    TraceReplay::start('Context Reset');
    TraceReplay::context(['temp' => 'val']);
    TraceReplay::step('Step 1', fn () => null);
    TraceReplay::step('Step 2', fn () => null);

    $steps = TraceReplay::getCurrentTrace()->steps()->orderBy('step_order')->get();

    expect($steps[0]->state_snapshot)->toMatchArray(['temp' => 'val'])
        ->and($steps[1]->state_snapshot)->toBe([]);
});

it('context() resets after checkpoint consumes it', function () {
    TraceReplay::start('Context Checkpoint Reset');
    TraceReplay::context(['ctx' => 'data']);
    TraceReplay::checkpoint('CP1');
    TraceReplay::checkpoint('CP2');

    $steps = TraceReplay::getCurrentTrace()->steps()->orderBy('step_order')->get();

    expect($steps[0]->state_snapshot)->toMatchArray(['ctx' => 'data'])
        ->and($steps[1]->state_snapshot)->toBe([]);
});

// ── TraceStep Model ──────────────────────────────────────────────────────────

it('TraceStep duration_color accessor returns correct color', function () {
    TraceReplay::start('Color Test');
    TraceReplay::step('Fast', fn () => null);

    $step = TraceReplay::getCurrentTrace()->steps()->first();
    $step->update(['duration_ms' => 10]);
    expect($step->fresh()->duration_color)->toBe('green');

    $step->update(['duration_ms' => 100]);
    expect($step->fresh()->duration_color)->toBe('yellow');

    $step->update(['duration_ms' => 500]);
    expect($step->fresh()->duration_color)->toBe('orange');

    $step->update(['duration_ms' => 2000]);
    expect($step->fresh()->duration_color)->toBe('red');
});

it('TraceStep belongs to a trace', function () {
    TraceReplay::start('Relation Test');
    TraceReplay::step('Step', fn () => null);

    $step = TraceStep::first();
    $trace = $step->trace;

    expect($trace)->toBeInstanceOf(Trace::class);
});

// ── Workspace & Project Models ───────────────────────────────────────────────

it('Workspace has many projects', function () {
    $workspace = Workspace::create(['id' => Str::uuid(), 'name' => 'Test WS']);
    $project = Project::create(['id' => Str::uuid(), 'workspace_id' => $workspace->id, 'name' => 'Test Proj']);

    expect($workspace->projects)->toHaveCount(1)
        ->and($workspace->projects->first()->name)->toBe('Test Proj')
        ->and($project->workspace->name)->toBe('Test WS');
});

it('Project has many traces', function () {
    $workspace = Workspace::create(['id' => Str::uuid(), 'name' => 'WS']);
    $project = Project::create(['id' => Str::uuid(), 'workspace_id' => $workspace->id, 'name' => 'Proj']);

    Trace::factory()->create(['project_id' => $project->id]);
    Trace::factory()->create(['project_id' => $project->id]);

    expect($project->traces)->toHaveCount(2);
});

it('Trace belongs to a project', function () {
    $workspace = Workspace::create(['id' => Str::uuid(), 'name' => 'WS']);
    $project = Project::create(['id' => Str::uuid(), 'workspace_id' => $workspace->id, 'name' => 'My Project']);

    $trace = Trace::factory()->create(['project_id' => $project->id]);

    expect($trace->project)->toBeInstanceOf(Project::class)
        ->and($trace->project->name)->toBe('My Project');
});

// ── Accessors (extra coverage) ───────────────────────────────────────────────

it('total_memory_usage accessor sums step memory', function () {
    TraceReplay::start('Memory Test');
    TraceReplay::step('Step A', fn () => null);
    TraceReplay::step('Step B', fn () => null);

    $trace = TraceReplay::getCurrentTrace();
    $steps = $trace->steps()->orderBy('step_order')->get();
    $steps[0]->update(['memory_usage' => 1024]);
    $steps[1]->update(['memory_usage' => 2048]);

    expect($trace->total_memory_usage)->toBe(3072);
});

it('completion_percentage returns 100 for successful traces', function () {
    $trace = Trace::factory()->create(['status' => 'success']);
    expect($trace->completion_percentage)->toBe(100);
});

it('completion_percentage returns 0 for trace with no steps', function () {
    $trace = Trace::factory()->create(['status' => 'error']);
    expect($trace->completion_percentage)->toBe(0);
});

it('completion_percentage calculates correctly for error trace with steps', function () {
    TraceReplay::start('Partial');
    TraceReplay::step('Step 1', fn () => null);
    TraceReplay::step('Step 2', fn () => null);
    try {
        TraceReplay::step('Step 3', fn () => throw new Exception('fail'));
    } catch (Exception) {
    }
    TraceReplay::end('error');

    $trace = Trace::latest()->first();
    // 3 steps total, error at step_order 3. Percentage = ((3-1)/3)*100 = 66.67 ≈ 67
    expect($trace->completion_percentage)->toBe(67);
});

// ── PayloadMasker Edge Cases ─────────────────────────────────────────────────

it('PayloadMasker returns non-array values unchanged', function () {
    $masker = new PayloadMasker;

    expect($masker->mask('string'))->toBe('string')
        ->and($masker->mask(42))->toBe(42)
        ->and($masker->mask(null))->toBeNull();
});

it('PayloadMasker is case-insensitive', function () {
    config(['trace-replay.mask_fields' => ['Authorization']]);
    $masker = new PayloadMasker;

    $result = $masker->mask(['AUTHORIZATION' => 'Bearer xyz', 'name' => 'test']);

    expect($result['AUTHORIZATION'])->toBe('********')
        ->and($result['name'])->toBe('test');
});

// ── NotificationService ──────────────────────────────────────────────────────

it('NotificationService sends mail on failure', function () {
    // Mail::raw cannot be asserted via Mail::fake() (known Laravel limitation).
    // Instead, spy on the facade to verify it was called.
    Mail::shouldReceive('raw')
        ->once()
        ->withArgs(function (string $body, Closure $callback) {
            // Verify the body contains the trace name
            return str_contains($body, 'Notified Trace');
        });

    config([
        'trace-replay.notifications.on_failure' => true,
        'trace-replay.notifications.channels' => ['mail'],
        'trace-replay.notifications.mail.to' => 'test@example.com',
    ]);

    $trace = Trace::factory()->create(['status' => 'error', 'name' => 'Notified Trace']);

    $service = app(NotificationService::class);
    $service->notifyFailure($trace);
});

it('NotificationService skips mail when no recipient configured', function () {
    Mail::fake();

    config([
        'trace-replay.notifications.channels' => ['mail'],
        'trace-replay.notifications.mail.to' => null,
    ]);

    $trace = Trace::factory()->create(['status' => 'error']);
    app(NotificationService::class)->notifyFailure($trace);

    Mail::assertNothingSent();
});

it('NotificationService sends slack notification', function () {
    Http::fake(['*' => Http::response([], 200)]);

    config([
        'trace-replay.notifications.channels' => ['slack'],
        'trace-replay.notifications.slack.webhook_url' => 'https://hooks.slack.test/webhook',
    ]);

    $trace = Trace::factory()->create(['status' => 'error', 'name' => 'Slack Trace']);
    app(NotificationService::class)->notifyFailure($trace);

    Http::assertSent(fn ($request) => $request->url() === 'https://hooks.slack.test/webhook');
});

it('NotificationService skips slack when no webhook configured', function () {
    Http::fake();

    config([
        'trace-replay.notifications.channels' => ['slack'],
        'trace-replay.notifications.slack.webhook_url' => null,
    ]);

    $trace = Trace::factory()->create(['status' => 'error']);
    app(NotificationService::class)->notifyFailure($trace);

    Http::assertNothingSent();
});

// ── Step DB query tracking ───────────────────────────────────────────────────

it('step records db query count when tracking is enabled', function () {
    config(['trace-replay.track_db_queries' => true]);

    TraceReplay::start('DB Tracking');
    TraceReplay::step('Query Step', function () {
        // This query will be tracked
        Trace::count();
    });

    $step = TraceReplay::getCurrentTrace()->steps()->first();

    expect($step->db_query_count)->toBeGreaterThanOrEqual(1);
});

it('step does not track queries when disabled', function () {
    config(['trace-replay.track_db_queries' => false]);

    TraceReplay::start('No DB Tracking');
    TraceReplay::step('Silent Step', function () {
        Trace::count();
    });

    $step = TraceReplay::getCurrentTrace()->steps()->first();

    expect($step->db_query_count)->toBe(0);
});

// ── step() without active trace ──────────────────────────────────────────────

it('step() executes callback even without active trace', function () {
    // No TraceReplay::start() called
    $result = TraceReplay::step('Orphan', fn () => 'orphan-result');

    expect($result)->toBe('orphan-result');
});

it('checkpoint() does nothing without active trace', function () {
    // Should not throw
    TraceReplay::checkpoint('No-op');

    expect(TraceStep::count())->toBe(0);
});

it('end() does nothing without active trace', function () {
    // Should not throw
    TraceReplay::end();

    expect(true)->toBeTrue();
});

// ── Facade context() returns self ────────────────────────────────────────────

it('context() returns the manager for chaining', function () {
    TraceReplay::start('Chain Test');

    $result = app('trace-replay')->context(['x' => 1]);

    expect($result)->toBeInstanceOf(TraceReplayManager::class);
});

// ── Duration Precision ──────────────────────────────────────────────────────

it('end() records a positive duration_ms', function () {
    $trace = TraceReplay::start('Duration Test');

    // Simulate a tiny bit of work
    usleep(5000); // 5 ms

    TraceReplay::end('success');

    $fresh = $trace->fresh();
    expect($fresh->duration_ms)->toBeGreaterThan(0)
        ->and($fresh->duration_ms)->toBeLessThan(5000); // sanity upper bound
});

// ── Export Command — invalid directory ──────────────────────────────────────

it('trace-replay:export fails on invalid output directory', function () {
    $trace = Trace::factory()->create();

    $this->artisan('trace-replay:export', [
        'id' => $trace->id,
        '--format' => 'json',
        '--output' => '/nonexistent/dir/trace.json',
    ])->assertExitCode(1);
});

// ── Prune Command — invalid status ─────────────────────────────────────────

it('trace-replay:prune rejects invalid status', function () {
    $this->artisan('trace-replay:prune', ['--days' => 30, '--status' => 'invalid'])
        ->assertExitCode(1);
});

// ── Dashboard — invalid status ignored ─────────────────────────────────────

it('dashboard index ignores invalid status filter', function () {
    Trace::factory()->create(['status' => 'success', 'name' => 'Valid Trace']);

    $response = $this->get('/trace-replay?status=nonexistent');

    $response->assertOk();
    // Invalid status is ignored — all traces should be shown
    $response->assertSee('Valid Trace');
});

// ── Dashboard — replay endpoint ────────────────────────────────────────────

it('dashboard replay endpoint returns error for trace without request payload', function () {
    $trace = Trace::factory()->create();

    $response = $this->postJson("/trace-replay/traces/{$trace->id}/replay");

    $response->assertStatus(400);
    $response->assertJsonPath('status', 'error');
});

// ── Dashboard — AI prompt endpoint ─────────────────────────────────────────

it('dashboard AI prompt endpoint works for error trace', function () {
    TraceReplay::start('AI Dashboard Test');
    try {
        TraceReplay::step('Fail', fn () => throw new Exception('prompt test error'));
    } catch (Exception) {
    }
    TraceReplay::end('error');

    $trace = Trace::latest()->first();

    // No OpenAI key configured, so ai_response will be null
    $response = $this->postJson("/trace-replay/traces/{$trace->id}/ai-prompt");

    $response->assertOk();
    $response->assertJsonPath('status', 'success');
    $response->assertJsonStructure(['data' => ['prompt', 'ai_response']]);
});

// ── AiPromptService::callOpenAI ────────────────────────────────────────────

it('AiPromptService callOpenAI returns null when no key configured', function () {
    config(['trace-replay.ai.openai_api_key' => null]);

    $result = app(AiPromptService::class)->callOpenAI('test prompt');

    expect($result)->toBeNull();
});

it('AiPromptService callOpenAI returns response when key configured', function () {
    config(['trace-replay.ai.openai_api_key' => 'test-key']);

    Http::fake([
        'api.openai.com/*' => Http::response([
            'choices' => [['message' => ['content' => 'AI fix suggestion']]],
        ]),
    ]);

    $result = app(AiPromptService::class)->callOpenAI('test prompt');

    expect($result)->toBe('AI fix suggestion');
});

it('AiPromptService callOpenAI returns null on API failure', function () {
    config(['trace-replay.ai.openai_api_key' => 'test-key']);

    Http::fake([
        'api.openai.com/*' => Http::response([], 500),
    ]);

    $result = app(AiPromptService::class)->callOpenAI('test prompt');

    expect($result)->toBeNull();
});

// ── TraceBar Component ──────────────────────────────────────────────────────

it('TraceBar renders empty when disabled', function () {
    config(['trace-replay.enabled' => false]);

    $component = new TraceBar;
    $result = $component->render();

    expect($result)->toBe('');
});

it('TraceBar renders empty when show is false', function () {
    $component = new TraceBar(show: false);
    $result = $component->render();

    expect($result)->toBe('');
});

// ── Export Command — output to file ─────────────────────────────────────────

it('trace-replay:export writes to output file', function () {
    $trace = Trace::factory()->create(['name' => 'File Export']);
    $tmpFile = tempnam(sys_get_temp_dir(), 'tr_export_');

    $this->artisan('trace-replay:export', [
        'id' => $trace->id,
        '--format' => 'json',
        '--output' => $tmpFile,
    ])->assertExitCode(0);

    $content = file_get_contents($tmpFile);
    expect($content)->toContain('File Export');

    @unlink($tmpFile);
});

// ── ExportTraceCommand — status validation ─────────────────────────────────

it('trace-replay:export rejects invalid status', function () {
    $this->artisan('trace-replay:export', ['--status' => 'invalid', '--format' => 'json'])
        ->assertExitCode(1);
});

// ── AiPromptService — null duration_ms ────────────────────────────────────

it('AiPromptService handles null duration_ms without crash', function () {
    $trace = Trace::factory()->create([
        'status' => 'error',
        'duration_ms' => null,
    ]);

    // Create a step with error to trigger the prompt path
    TraceStep::create([
        'trace_id' => $trace->id,
        'label' => 'Broken',
        'status' => 'error',
        'error_reason' => 'test error',
        'step_order' => 1,
        'duration_ms' => 0,
    ]);

    $prompt = app(AiPromptService::class)->generateFixPrompt($trace->load('steps'));

    // Should not crash and should contain "0.00 ms" for null duration
    expect($prompt)->toContain('0.00 ms')
        ->and($prompt)->toContain('Broken');
});

// ── NotificationService — null duration_ms ────────────────────────────────

it('NotificationService handles null duration_ms in mail', function () {
    Mail::shouldReceive('raw')
        ->once()
        ->withArgs(function (string $body) {
            // Should show "0 ms" not " ms"
            return str_contains($body, '0 ms');
        });

    config([
        'trace-replay.notifications.channels' => ['mail'],
        'trace-replay.notifications.mail.to' => 'test@example.com',
    ]);

    $trace = Trace::factory()->create([
        'status' => 'error',
        'name' => 'Null Duration',
        'duration_ms' => null,
    ]);

    app(NotificationService::class)->notifyFailure($trace);
});

it('NotificationService handles null duration_ms in slack', function () {
    Http::fake(['*' => Http::response([], 200)]);

    config([
        'trace-replay.notifications.channels' => ['slack'],
        'trace-replay.notifications.slack.webhook_url' => 'https://hooks.slack.test/webhook',
    ]);

    $trace = Trace::factory()->create([
        'status' => 'error',
        'duration_ms' => null,
    ]);

    app(NotificationService::class)->notifyFailure($trace);

    Http::assertSent(fn ($request) => str_contains(json_encode($request->data()), '0 ms')
    );
});

// ── MCP RPC trigger_replay ──────────────────────────────────────────────────

it('MCP RPC trigger_replay returns error for trace without payload', function () {
    $trace = Trace::factory()->create();

    $response = $this->postJson('/api/trace-replay/mcp', [
        'method' => 'trigger_replay',
        'params' => ['trace_id' => $trace->id],
        'id' => 10,
    ]);

    $response->assertOk();
    $response->assertJsonPath('jsonrpc', '2.0');
    // Should have an error because no request payload
    $response->assertJsonStructure(['error' => ['code', 'message']]);
});
