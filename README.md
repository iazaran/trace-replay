# TraceReplay

> **High-fidelity process tracking, deterministic replay, and AI-powered debugging for Laravel — production & enterprise ready.**

[![Latest Version](https://img.shields.io/packagist/v/iazaran/tracereplay)](https://packagist.org/packages/iazaran/tracereplay)
[![PHP](https://img.shields.io/badge/PHP-8.2%2B-blue)](https://php.net)
[![Laravel](https://img.shields.io/badge/Laravel-10%20|%2011%20|%2012%20|%2013-red)](https://laravel.com)
[![License: MIT](https://img.shields.io/badge/License-MIT-green)](LICENSE)
[![Tests](https://img.shields.io/badge/tests-90%20passing-brightgreen)](#testing)

TraceReplay is not a standard error logger. It is a full-fledged **execution tracer** that captures every step of your complex workflows, reconstructs them with a waterfall timeline, and offers one-click AI debugging when things go wrong.

![TraceReplay Dashboard](https://raw.githubusercontent.com/iazaran/tracereplay/refs/heads/main/art/preview.png)

---

## ✨ Key Features

| Feature | TraceReplay | Telescope | Debugbar | Clockwork |
|---|---|---|---|---|
| Manual step instrumentation | ✅ | ❌ | ❌ | ❌ |
| Waterfall timeline UI | ✅ | ❌ | ✅ | ✅ |
| Deterministic HTTP replay | ✅ | ❌ | ❌ | ❌ |
| Visual JSON diff on replay | ✅ | ❌ | ❌ | ❌ |
| AI fix-prompt generator | ✅ | ❌ | ❌ | ❌ |
| MCP / AI-agent JSON-RPC API | ✅ | ❌ | ❌ | ❌ |
| DB query tracking per step | ✅ | ✅ | ✅ | ✅ |
| Memory tracking per step | ✅ | ❌ | ✅ | ✅ |
| PII / sensitive-field masking | ✅ | ❌ | ❌ | ❌ |
| Queue-job auto-tracing | ✅ | ✅ | ❌ | ❌ |
| Artisan-command auto-tracing | ✅ | ✅ | ❌ | ❌ |
| Sampling rate control | ✅ | ❌ | ❌ | ❌ |
| Dashboard auth gate | ✅ | ✅ | ❌ | N/A |
| Pruning / data retention | ✅ | ✅ | ❌ | ❌ |
| Multi-tenant (workspace/project) | ✅ | ❌ | ❌ | ❌ |
| Laravel 10 / 11 / 12 support | ✅ | ✅ | ✅ | ✅ |

---

## 🛠 Installation

```bash
composer require iazaran/tracereplay
```

Publish the config and migrations:

```bash
php artisan vendor:publish --tag=tracereplay-config
php artisan vendor:publish --tag=tracereplay-migrations
```

Run migrations:

```bash
php artisan migrate
```

> **Note:** Migrations use `json` columns (not `jsonb`) for full MySQL 5.7+, MariaDB, PostgreSQL, and SQLite compatibility.

#### Publishing Views (Recommended)

TraceReplay ships with a polished, dark-themed dashboard featuring a waterfall timeline, syntax-highlighted JSON inspector, and live stats — all styled and ready to use out of the box. Publishing the views lets you customise the layout, colours, or add your own branding:

```bash
php artisan vendor:publish --tag=tracereplay-views
```

This copies the Blade templates to `resources/views/vendor/tracereplay/` where you can edit them freely. The package will automatically use your published versions instead of its built-in views.

---

## ⚙️ Configuration

Open `config/tracereplay.php`. Every option is documented inline; the key ones are:

```php
return [
    // Globally enable or disable tracing (useful for CI)
    'enabled' => env('TRACEREPLAY_ENABLED', true),

    // Probabilistic sampling — 1.0 = always trace, 0.1 = trace 10% of requests
    'sample_rate' => env('TRACEREPLAY_SAMPLE_RATE', 1.0),

    // Multi-tenant project ID (optional)
    'project_id' => env('TRACEREPLAY_PROJECT_ID', null),

    // Automatically mask these keys in request/response payloads
    'mask_fields' => ['password', 'password_confirmation', 'token', 'api_key',
                      'authorization', 'secret', 'credit_card', 'cvv', 'ssn', 'private_key'],

    // Track DB queries inside each step
    'track_db_queries' => env('TRACEREPLAY_TRACK_DB', true),

    // Dashboard route middleware (add 'auth' or custom gate middleware for production)
    'middleware'     => ['web'],
    'api_middleware' => ['api'],

    // IP allowlist for the dashboard (exact match; empty = allow all)
    'allowed_ips' => array_filter(explode(',', env('TRACEREPLAY_ALLOWED_IPS', ''))),

    // Async step persistence via a queue
    'queue' => [
        'enabled'    => env('TRACEREPLAY_QUEUE_ENABLED', false),
        'connection' => env('TRACEREPLAY_QUEUE_CONNECTION', env('QUEUE_CONNECTION', 'sync')),
        'queue'      => env('TRACEREPLAY_QUEUE_NAME', 'default'),
    ],

    // Replay engine
    'replay' => [
        'default_base_url' => env('TRACEREPLAY_REPLAY_URL', env('APP_URL', 'http://localhost')),
        'timeout'          => env('TRACEREPLAY_REPLAY_TIMEOUT', 30),
    ],

    // Auto-pruning retention period (days)
    'retention_days' => env('TRACEREPLAY_RETENTION_DAYS', 30),

    // Failure notifications (email / Slack webhook)
    'notifications' => [
        'on_failure' => env('TRACEREPLAY_NOTIFY_ON_FAILURE', false),
        'channels'   => ['mail'],
        'mail'       => ['to' => env('TRACEREPLAY_NOTIFY_EMAIL')],
        'slack'      => ['webhook_url' => env('TRACEREPLAY_SLACK_WEBHOOK')],
    ],

    // OpenAI integration for in-dashboard AI responses
    'ai' => [
        'openai_api_key' => env('TRACEREPLAY_OPENAI_KEY'),
        'model'          => env('TRACEREPLAY_OPENAI_MODEL', 'gpt-4o'),
    ],

    // Auto-tracing for jobs and artisan commands (registered automatically)
    'auto_trace' => [
        'jobs'     => env('TRACEREPLAY_AUTO_TRACE_JOBS', true),
        'commands' => env('TRACEREPLAY_AUTO_TRACE_COMMANDS', false),
        'exclude_commands' => [
            'queue:work', 'queue:listen', 'horizon', 'schedule:run',
            'schedule:work', 'tracereplay:prune', 'tracereplay:export',
        ],
    ],
];
```

---

## 🚀 Usage

### Manual Instrumentation

Wrap any complex logic in `TraceReplay::step()` — each callback's return value is passed through transparently.

```php
use TraceReplay\Facades\TraceReplay;

class BookingService
{
    public function handleBooking(array $payload): void
    {
        TraceReplay::start('Flight Booking', ['channel' => 'web']);

        try {
            $inventory = TraceReplay::step('Validate Inventory', function () use ($payload) {
                return Inventory::check($payload['flight_id']);
            });

            TraceReplay::checkpoint('Inventory validated', ['seats_left' => $inventory->seats]);

            TraceReplay::context(['user_tier' => auth()->user()->tier]);

            TraceReplay::step('Charge Credit Card', function () use ($payload) {
                return PaymentGateway::charge($payload['amount']);
            });

            TraceReplay::end('success');

        } catch (\Exception $e) {
            TraceReplay::end('error');
            throw $e;
        }
    }
}
```

**API Reference:**

| Method | Description |
|---|---|
| `TraceReplay::start(name, tags[])` | Start a new trace; returns `Trace` or `null` if disabled/sampled-out |
| `TraceReplay::step(label, callable, extra[])` | Wrap callable, record timing, memory, DB queries, errors |
| `TraceReplay::measure(label, callable)` | Alias for `step()` — semantic clarity for benchmarks |
| `TraceReplay::checkpoint(label, state[])` | Record a zero-overhead breadcrumb (no callable) |
| `TraceReplay::context(array)` | Merge data into the next step's `state_snapshot` |
| `TraceReplay::end(status)` | Finalise trace; status: `success` or `error` |
| `TraceReplay::getCurrentTrace()` | Returns the active `Trace` model (or `null`) |

---

### Auto HTTP Ingestion (Middleware)

Automatically trace every HTTP request. Add to `app/Http/Kernel.php`:

```php
protected $middlewareGroups = [
    'web' => [
        // ...
        \TraceReplay\Http\Middleware\TraceMiddleware::class,
    ],
];
```

For Laravel 11+ (using `bootstrap/app.php`):

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->append(\TraceReplay\Http\Middleware\TraceMiddleware::class);
})
```

---

### Auto Queue-Job Tracing

Queue jobs are automatically traced when `auto_trace.jobs` is enabled (default: `true`). No manual listener registration is needed — the service provider wires everything up.

To disable, set `TRACEREPLAY_AUTO_TRACE_JOBS=false` in your `.env`.

---

### Auto Artisan-Command Tracing

Artisan commands can be auto-traced by enabling `auto_trace.commands`:

```env
TRACEREPLAY_AUTO_TRACE_COMMANDS=true
```

Internal commands like `queue:work`, `horizon`, and `tracereplay:prune` are excluded by default (see `auto_trace.exclude_commands` in the config).

---

### Debug Bar Component

Drop the `<x-tracereplay-trace-bar />` Blade component into your layout for instant in-page trace inspection:

```blade
{{-- resources/views/layouts/app.blade.php --}}
@if(config('app.debug'))
    <x-tracereplay-trace-bar />
@endif
```

---

## 🎨 The Dashboard

Access the built-in dashboard at `https://your-app.com/tracereplay`.

**Features:**
- **Waterfall timeline** — visual bars show each step's exact duration relative to the total trace
- **Live stats** — auto-refreshing counters (total traces, failed, avg duration)
- **Search & filter** — filter by name, IP, user ID; toggle failed-only view
- **Step inspector** — syntax-highlighted JSON for request payload, response payload, and state snapshot
- **Replay engine** — re-execute any HTTP step and view a structural JSON diff
- **AI Fix Prompt** — one-click prompt ready for Cursor, ChatGPT, or Claude

### Securing the Dashboard

Add authentication or authorization middleware in `config/tracereplay.php`:

```php
'middleware' => ['web', 'auth', 'can:view-tracereplay'],
```

Then define the gate:

```php
// app/Providers/AuthServiceProvider.php
Gate::define('view-tracereplay', function ($user) {
    return in_array($user->email, config('tracereplay.admin_emails', []));
});
```

Or use IP allowlisting (exact match, comma-separated via env):

```env
TRACEREPLAY_ALLOWED_IPS=203.0.113.5,10.0.0.1
```

---

## 🤖 AI Debugging

For any failed trace the dashboard shows an **AI Fix Prompt** button that generates a structured markdown prompt including:

- Full execution timeline with timing and DB stats
- The exact error message, file, line, and first 20 stack frames
- Request/response payloads (sensitive fields masked)
- Step-by-step state snapshots

Paste this into any LLM. Optionally configure your OpenAI key and click **"Ask AI"** to get an answer directly in the dashboard.

---

## 🤖 MCP / AI-Agent JSON-RPC API

TraceReplay exposes a JSON-RPC 2.0 endpoint at `POST /api/tracereplay/mcp` for autonomous AI agents.

**Available methods:**

| Method | Params | Returns |
|---|---|---|
| `list_traces` | `limit`, `status` | Array of trace summaries |
| `get_trace_context` | `trace_id` | Full trace with steps |
| `generate_fix_prompt` | `trace_id` | Markdown debugging prompt |
| `trigger_replay` | `trace_id` | Replay result + JSON diff |

Example request:

```json
{
  "jsonrpc": "2.0",
  "method": "generate_fix_prompt",
  "params": { "trace_id": "9b12f7e4-..." },
  "id": 1
}
```

---

## 🧹 Data Retention

Automatically prune old traces with the built-in Artisan command. Add to your scheduler:

```php
// app/Console/Kernel.php
$schedule->command('tracereplay:prune --days=30')->daily();
```

Options:

```bash
php artisan tracereplay:prune --days=30                # Delete traces older than 30 days
php artisan tracereplay:prune --days=30 --dry-run      # Preview what would be deleted
php artisan tracereplay:prune --days=7 --status=error  # Only prune error traces
```

---

## 📤 Export

Export a trace to JSON or CSV for archiving or external analysis:

```bash
php artisan tracereplay:export {id} --format=json
php artisan tracereplay:export {id} --format=csv
php artisan tracereplay:export {id} --format=json --output=/tmp/trace.json
php artisan tracereplay:export --status=error --format=json  # Export all error traces
```

---

## 🧪 Testing

```bash
composer install
./vendor/bin/pest
```

90 tests, 183 assertions. The test suite covers:
- Trace lifecycle (start, step, checkpoint, context, end, duration precision)
- Error capturing, step ordering, DB query tracking
- Model scopes (`failed`, `successful`, `search`)
- Model accessors (`error_step`, `total_db_queries`, `total_memory_usage`, `completion_percentage`)
- `PayloadMasker` — recursive PII field redaction, case-insensitivity
- `AiPromptService` — prompt generation, OpenAI integration (mocked), null-safety
- `NotificationService` — mail and Slack dispatch, null-safety
- `ReplayService` — HTTP replay and JSON diff
- Dashboard — index, filters, search, show, stats, export, replay, AI prompt
- MCP API — REST endpoints and JSON-RPC (all methods + error handling)
- Middleware — TraceMiddleware (route skipping, disabled config), AuthMiddleware (IP allow/block)
- Artisan `tracereplay:prune` (delete, dry-run, status filter, validation)
- Artisan `tracereplay:export` (JSON, CSV, file output, status filter, validation)
- Blade components — TraceBar rendering with enabled/disabled states

---

## 🛡️ License

The MIT License (MIT). See [LICENSE](LICENSE) for details.
