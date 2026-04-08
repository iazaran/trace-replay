# TraceReplay — Recommendations to Dominate the Market

> Grounded in a full code-level review (every file, every line) of the package as of April 2026.
> Each item references the exact file/line responsible so an AI agent can act on it directly.

---

## 🔴 Critical — Fix Before Next Release

### 1. Dashboard is open to the public by default
**File:** `config/trace-replay.php` → `middleware` key
**Problem:** Default middleware is `['web']` — no authentication. Any visitor can read every trace, including masked-but-still-sensitive payloads.
**Fix:** Change the default to `['web', 'auth']` and add a prominent warning in the README that the middleware **must** be hardened for production. Optionally ship a built-in `Gate::define('view-trace-replay', ...)` check that defaults to `false` unless opted in.

---

### 2. `TraceReplayManager` is a singleton with mutable request state — breaks under Laravel Octane
**File:** `src/TraceReplayManager.php` — `$currentTrace`, `$stepCounter`, `$stepBuffer`, `$pendingContext`, `$startedAtMicrotime`
**Problem:** All of these are instance properties on a singleton. Under Octane (Swoole / RoadRunner / FrankenPHP), the same singleton lives across requests. A trace from Request A bleeds into Request B.
**Fix:** Bind `TraceReplayManager` as `scoped` instead of `singleton` in `TraceReplayServiceProvider.php` line 28, OR move all mutable state into a per-request container resolved via `app()->make()` inside each method. Add an Octane compatibility note to the README.

---

### 3. `$stepBuffer` is dead code — batch-insert never fires
**File:** `src/TraceReplayManager.php` lines 18-19, 244-245
**Problem:** `$stepBuffer` is declared, reset on `start()` and `end()`, but `persistStep()` never populates it for a batch flush. Every step is either queued individually or saved individually.
**Fix:** Either remove the property and the batch-insert concept from the public surface (simplify), or implement a real `flushStepBuffer()` call at `end()` time using a single `TraceStep::insert()` call. The batch approach would dramatically reduce DB round-trips for traces with many steps.

---

### 4. `sample_rate` is only honoured by the middleware — manual `start()` calls ignore it
**File:** `src/Http/Middleware/TraceMiddleware.php` lines 20-22 vs `src/TraceReplayManager.php::start()`
**Problem:** The sampling check lives in `TraceMiddleware`, not in `TraceReplayManager::start()`. Any developer who calls `TraceReplay::start()` manually bypasses sampling entirely, producing 100 % recording even when `sample_rate = 0.1`. The config comment even says *"Manual TraceReplay::start() calls are never sampled"* (line 20) — this is a design gap, not a feature, because queue-job and artisan-command auto-tracing also call `start()` and will always record at 100 %.
**Fix:** Move the sampling logic into `TraceReplayManager::start()` with an optional `$forceSample = false` parameter for callers that explicitly want to bypass it.

---

### 5. Global `DB::flushQueryLog()` in `step()` can corrupt the host app's query log
**File:** `src/TraceReplayManager.php` lines 91-93, 113-117
**Problem:** `DB::flushQueryLog()` and `DB::enableQueryLog()` are global operations. If the host application also uses the query log (e.g., for its own profiling), TraceReplay will silently destroy that data. Nested `step()` calls also interfere with each other.
**Fix:** Capture a snapshot of the query log length *before* the step runs (`$before = count(DB::getQueryLog())`), then diff after the callback returns. This is additive and non-destructive and works with nested steps.

---

### 6. No cache query tracking at all — major observability gap
**File:** `src/TraceReplayManager.php` — `step()` method
**Problem:** DB queries are tracked per step, but cache operations (hits, misses, writes, forgets) are completely invisible. Cache is the #1 source of subtle production bugs (stale data, thundering herd, cache stampede) and **no competitor tracks it either**. This is your chance to be first.
**Fix:** Inside `step()`, listen to Laravel's cache events (`CacheHit`, `CacheMissed`, `KeyWritten`, `KeyForgotten`) for the duration of the callback — the same pattern as the DB query log. Add `cache_hit_count`, `cache_miss_count` columns to `tr_trace_steps`. Display in the step inspector and waterfall tooltip.

---

### 7. No HTTP client (outgoing request) tracking per step
**File:** `src/TraceReplayManager.php` — `step()` method
**Problem:** When a step calls an external API via `Http::get()` / Guzzle, the outgoing request URL, status, and duration are invisible. Developers have no way to see that "Step: Charge Credit Card" actually hit `https://api.stripe.com/v1/charges` and took 800 ms. Telescope tracks this globally, but not per-step — TraceReplay can own this niche.
**Fix:** Record outgoing HTTP requests by listening to Laravel's `RequestSending` and `ResponseReceived` events (or `Http::globalMiddleware()`) within the step scope. Store them in the step's `state_snapshot` or a dedicated `http_calls` JSON column. Show in the step inspector alongside DB queries.

---

## 🟠 High-Impact — Ship in Next Minor

### 8. No `TraceReplayFake` testing helper for package consumers
**Problem:** Developers who instrument their own code with `TraceReplay::step()` have no clean way to assert on traces in their own test suites. They must boot a real database.
**Fix:** Provide a `TraceReplay::fake()` method (following Laravel's `Mail::fake()` / `Event::fake()` pattern) that returns a `TraceReplayFake` instance. Expose assertions like `TraceReplayFake::assertStepRecorded('label')`, `assertTraceEnded('success')`, `assertStepCount(n)`. This is the #1 thing that will make enterprise teams adopt — if they can't test around your SDK, they won't ship it.

---

### 9. AI is locked to OpenAI — abstract to a driver interface
**File:** `src/Services/AiPromptService.php` lines 83-106
**Problem:** `callOpenAI()` hard-codes the OpenAI API endpoint, model format, and response shape. Many teams use Anthropic Claude, Google Gemini, Mistral, or local Ollama instances. Locking to one provider is a competitive disadvantage.
**Fix:** Extract an `AiDriver` interface with a `complete(string $prompt): ?string` method. Ship `OpenAiDriver`, `AnthropicDriver`, and `OllamaDriver` as built-in implementations. Let the config key `ai.driver` select which one to use. Rename the config section from `ai.openai_api_key` to `ai.api_key` with a `ai.driver` discriminator.

---

### 10. `captureResponseOnLastStep()` fires an extra DB query on every single HTTP request
**File:** `src/TraceReplayManager.php` lines 200-212
**Problem:** `TraceStep::where('trace_id', ...)->orderBy('step_order', 'desc')->first()` executes a SELECT on every terminated request just to find the last step. Under load, this is thousands of unnecessary SELECTs per minute.
**Fix:** Keep a reference to the last persisted `TraceStep` on the manager (`$this->lastStep`) and update it directly, skipping the DB read entirely.

---

### 11. Eager-load steps before calling computed accessors — severe N+1 on dashboard
**File:** `src/Models/Trace.php` — `getErrorStepAttribute()`, `getTotalDbQueriesAttribute()`, `getTotalMemoryUsageAttribute()`, `getCompletionPercentageAttribute()`
**File:** `src/Http/Controllers/DashboardController.php` line 16 — `Trace::withCount('steps')` (count only, not loaded)
**Problem:** Each accessor calls `$this->steps()` (the relationship query builder), not `$this->steps` (the loaded collection). Listing 50 traces on the dashboard, each calling 4 accessors = 200+ extra queries (N+1). The `index()` action only does `withCount('steps')` which doesn't help the accessors.
**Fix:** (a) Rewrite all accessors to use `$this->steps` (the collection, not the query builder). (b) Use `Trace::with('steps')` in the controller. (c) Add a `$with = ['steps']` default on the model if steps are almost always needed.

---

### 12. Replay endpoint has no protection against side effects
**File:** `src/Services/ReplayService.php`
**Problem:** Replaying a trace re-executes the original HTTP request verbatim — including write operations (POST, PUT, DELETE, PATCH). This can double-charge a credit card, create duplicate records, or trigger emails. There is no warning, no dry-run flag, and no confirmation gate.
**Fix:** (a) Show a clear "This will re-execute the request — side effects may occur" warning in the UI before replaying non-GET requests. (b) Add a `replay.allow_mutating_methods` config key (default `false`) that blocks replay for POST/PUT/PATCH/DELETE unless explicitly opted in. (c) Support a `dry_run` flag that mocks the HTTP call.

---

### 13. No per-step recording of the actual DB queries — only counts
**File:** `src/TraceReplayManager.php` lines 112-116, `src/Models/TraceStep.php`
**Problem:** The step records `db_query_count` and `db_query_time_ms` but throws away the actual SQL strings, bindings, and per-query timings from `DB::getQueryLog()`. When debugging a slow step with 47 queries, the developer has no idea *which* queries ran or which was slow.
**Fix:** Add a `db_queries` JSON column to `tr_trace_steps`. Store the query log (SQL + bindings + time) for each step, truncated to a configurable max (e.g., 50 queries) to avoid unbounded storage. Add a toggle `track_db_query_details` in config (default `false` for production, `true` for local/staging). Display in the step inspector as a collapsible SQL list.

---

### 14. No mail / notification tracking per step
**Problem:** If a step sends an email or dispatches a notification, there is no record of it. This is critical for debugging "why didn't the user receive their confirmation email?" scenarios.
**Fix:** Listen to `Illuminate\Mail\Events\MessageSending` and `Illuminate\Notifications\Events\NotificationSending` within the step scope. Record the recipient, mailable class, and channel. Store in the step's state or a dedicated column. This is something **no competitor does**.

---

### 15. MCP API has no authentication at all
**File:** `routes/api.php` line 9 — `config('trace-replay.api_middleware', ['api'])`
**Problem:** The MCP/JSON-RPC endpoint is protected only by the `api` middleware group, which by default has no authentication. Any client on the internet can call `list_traces`, `get_trace_context`, `generate_fix_prompt`, and `trigger_replay` (which actually fires real HTTP requests against your app).
**Fix:** (a) Add a bearer-token auth middleware that validates against a `TRACE_REPLAY_API_TOKEN` env key. (b) Default the API to disabled unless the token is set. (c) Document the security implications prominently.

---

### 16. `PersistTraceStepJob` uses `SerializesModels` but doesn't need it
**File:** `src/Jobs/PersistTraceStepJob.php` line 14
**Problem:** The job accepts a plain `array $stepData`, not an Eloquent model, yet it uses the `SerializesModels` trait. This trait adds overhead by attempting to serialize/deserialize model references and can cause unexpected behavior if the array accidentally contains model instances.
**Fix:** Remove `SerializesModels` from the `use` statement. The job only needs `Dispatchable`, `InteractsWithQueue`, and `Queueable`.

---

## 🟡 Medium — Competitive Differentiators

### 17. Add OpenTelemetry / W3C Trace Context header propagation
**Problem:** Teams using distributed tracing (Datadog, Jaeger, Tempo, Honeycomb) cannot correlate a TraceReplay trace with their APM spans because there is no `traceparent` / `tracestate` header support.
**Fix:** On `TraceMiddleware::handle()`, read the incoming `traceparent` header (W3C format) and store it on the `Trace` model (add a `trace_parent` column). Optionally set the same header on outgoing `Http::` calls made inside a step. This would make TraceReplay the **only** Laravel debug package with native distributed tracing correlation.

---

### 18. Support multiple notification channels beyond email and Slack
**File:** `src/Services/NotificationService.php`
**Problem:** Only `mail` and `slack` are supported for failure alerts. Teams use PagerDuty, Discord, Teams, OpsGenie, Telegram, and custom webhooks. The current implementation bypasses Laravel's notification system entirely (uses `Mail::raw()` and `Http::post()` directly).
**Fix:** Refactor to use Laravel's built-in notification system (`Notifiable` + `Notification::route()`). Ship a `TraceFailedNotification` class. Any channel the app already has installed (e.g., `laravel-discord-alerts`) will work automatically.

---

### 19. Add a `TraceReplay::tag()` shorthand and richer tag querying
**Problem:** Tags are stored as a JSON array but the dashboard and model scopes only search `name`, `user_id`, and `ip_address`. Tags are invisible in filters and unsearchable.
**Fix:** Add a `scopeWithTag(Builder $query, string $tag)` scope and expose a tag filter dropdown in the dashboard. Add a `TraceReplay::tag(string $key, mixed $value)` fluent method as a shorthand for `TraceReplay::context()`.

---

### 20. No GitHub Actions CI workflow — hurts trust on Packagist
**Problem:** There is no `.github/workflows/` directory. Packagist badges and the GitHub repository show no green check marks for CI, which reduces trust from potential adopters.
**Fix:** Add a workflow that runs `./vendor/bin/pest` on PHP 8.2 / 8.3 / 8.4 against Laravel 10, 11, and 12. Add a PHPStan level-5 check. Publish a real CI badge in the README replacing the static `90 passing` badge.

---

### 21. Static analysis is installed but not enforced
**File:** `vendor/phpstan` is present but there is no `phpstan.neon` at the root and no composer script for it.
**Fix:** Add a `phpstan.neon` config at level 5 (or higher), add `"analyse": "phpstan analyse src --level=5"` to `composer.json` scripts, and run it in CI.

---

### 22. `Workspace` and `Project` models exist but are not wired to multi-tenancy
**File:** `src/Models/Workspace.php`, `src/Models/Project.php`
**Problem:** Both models exist with full DB tables (migration lines 11-24), but only `project_id` is used on `Trace`. There is no `workspace_id` on traces, no scoping middleware, no dashboard filtering by project/workspace, and no documentation on how to use them. The dashboard doesn't even show project names.
**Fix:** Either fully implement workspace-level scoping (middleware that sets a `TraceReplay::forWorkspace($id)` context) with documentation and dashboard UI, or remove the models and their migration tables until they are ready. Shipping half-baked models adds confusion and creates dead DB tables.

---

### 23. Packagist keywords and description are too generic
**File:** `composer.json` → `keywords`
**Problem:** Keywords are `["laravel", "trace", "replay", "debug", "ai", "monitoring", "observability"]`. Competitors use the same words.
**Fix:** Add highly specific keywords: `"execution-tracing"`, `"deterministic-replay"`, `"ai-debugging"`, `"waterfall-timeline"`, `"step-instrumentation"`, `"mcp"`, `"json-rpc"`, `"pii-masking"`, `"cache-tracking"`, `"query-tracking"`. Update the description to: *"Step-level execution tracer with deterministic HTTP replay, waterfall timeline dashboard, PII masking, and AI-powered debugging for Laravel."*

---

### 24. Dashboard loads Tailwind CSS, Alpine.js, and Feather Icons from CDN — breaks in air-gapped / corporate environments
**File:** `resources/views/layout.blade.php` lines 15, 42, 45
**Problem:** The layout fetches `cdn.tailwindcss.com`, `cdn.jsdelivr.net`, `unpkg.com`, and `fonts.googleapis.com` at runtime. Many enterprise/government environments block external CDN requests entirely. The dashboard will render as unstyled HTML.
**Fix:** Bundle the CSS and JS assets locally as part of the package (e.g., a pre-built `public/vendor/trace-replay/` directory published via `artisan vendor:publish --tag=trace-replay-assets`). Fall back to CDN only if the local assets are not published.

---

### 25. `DashboardController::stats()` fires 6 separate DB queries — can be reduced to 1
**File:** `src/Http/Controllers/DashboardController.php` lines 69-87
**Problem:** `stats()` calls `Trace::count()`, `Trace::failed()->count()`, `Trace::successful()->count()`, `Trace::whereDate(...)->count()`, `avg('duration_ms')`, and `max('duration_ms')` — six separate queries. This endpoint is polled via `fetch()` on the index page.
**Fix:** Use a single raw query with conditional aggregation:
```sql
SELECT
  COUNT(*) as total,
  SUM(status = 'error') as failed,
  SUM(status = 'success') as success,
  SUM(DATE(started_at) = CURDATE()) as today,
  AVG(duration_ms) as avg_duration,
  MAX(duration_ms) as slowest
FROM tr_traces
```

---

### 26. Missing return type declarations on model relationships and controller methods
**Files:** `src/Models/Trace.php`, `src/Models/Project.php`, `src/Models/Workspace.php`, `src/Http/Controllers/DashboardController.php`, `src/Http/Controllers/Api/McpController.php`
**Problem:** Relationship methods (`project()`, `steps()`, `traces()`, `workspace()`) and controller actions (`index()`, `show()`, `listTraces()`, `getContext()`, etc.) have no return type declarations. This breaks IDE auto-completion, static analysis, and violates PHP 8.2+ best practices.
**Fix:** Add explicit return types: `HasMany`, `BelongsTo` for relationships; `View`, `JsonResponse` for controllers.

---

### 27. `TraceMiddleware` skips routes by string prefix — fragile and undocumented
**File:** `src/Http/Middleware/TraceMiddleware.php` line 26
**Problem:** `str_starts_with($request->path(), 'trace-replay')` assumes the dashboard is always mounted at `/trace-replay`. If a user changes the route prefix in a future version, the middleware will recursively trace its own dashboard, causing infinite loops or massive DB bloat.
**Fix:** Use the route name (e.g., `$request->route()?->getName()` starts with `trace-replay.`) instead of the URI path. Alternatively, read the prefix from config.

---

### 28. No payload size limits — unbounded JSON storage
**File:** `src/TraceReplayManager.php` — `step()`, `src/Http/Middleware/TraceMiddleware.php` — `terminate()`
**Problem:** Request payloads, response payloads, and state snapshots are stored as-is with no size limit. A single 10 MB API response body will be dumped into the `tr_trace_steps.response_payload` JSON column. This can crash MySQL (`max_allowed_packet`), bloat the DB, and slow the dashboard.
**Fix:** Add a `max_payload_size` config key (default 64 KB). Truncate payloads that exceed the limit with a `[TraceReplay: payload truncated at 64 KB]` marker. The `terminate()` method already truncates non-JSON responses to 2000 chars (line 81), but JSON responses have no limit at all.

---

### 29. Migration uses `float` for `duration_ms` — precision loss on MySQL
**File:** `database/migrations/2024_01_01_000000_create_trace_replay_tables.php` lines 31, 56
**Problem:** MySQL's `FLOAT` type is a 4-byte IEEE 754 single-precision number with only ~7 significant digits. A trace lasting 12345.67 ms may be stored as 12345.669921875. PostgreSQL's `real` has the same issue.
**Fix:** Use `$table->decimal('duration_ms', 12, 2)` for exact precision up to 9,999,999,999.99 ms. Same for `db_query_time_ms`.

---

### 30. No database indexes on `status` or `started_at` for the traces table — slow dashboard queries
**File:** `database/migrations/2024_01_01_000000_create_trace_replay_tables.php` lines 26-43
**Problem:** The dashboard's `index()` action filters by `status` and orders by `started_at` DESC. The `stats()` action also queries by `status` and `started_at`. Neither column is indexed. On tables with millions of rows, every dashboard load will full-table-scan.
**Fix:** Add `$table->index('status')` and `$table->index('started_at')`. Consider a composite index `['status', 'started_at']` for the most common query pattern.

---

## 🟢 Code Quality & Developer Experience

### 31. No `TraceStepFactory` — can't factory-build steps in tests
**File:** `database/factories/` — only `TraceFactory.php` exists
**Problem:** The test file manually creates `TraceStep` records with `TraceStep::create([...])` (e.g., line 1004). There is no factory for steps, which makes writing consumer tests verbose and error-prone.
**Fix:** Add a `TraceStepFactory` with sensible defaults (`label`, `status`, `step_order`, `duration_ms`, etc.) and a `belongsTo` `Trace` relationship configured.

---

### 32. Facade docblock `@method static static context(array $data)` is incorrect
**File:** `src/Facades/TraceReplay.php` line 14
**Problem:** The `context()` method on `TraceReplayManager` returns `static` (the manager instance), but on the Facade, `@method static static context(...)` means the Facade class itself, which is misleading. Calling `TraceReplay::context([...])->step(...)` works at runtime but IDEs show the wrong type.
**Fix:** Change to `@method static \TraceReplay\TraceReplayManager context(array $data)`.

---

### 33. `error_reason` is stored as a JSON-encoded string inside a `text` column — double encoding
**File:** `src/TraceReplayManager.php` lines 99-104
**Problem:** `$errorReason = json_encode([...])` produces a JSON string, which is then stored in the `error_reason` TEXT column as a raw string. When displayed in the dashboard, the show view renders it with `x-text="selectedStep?.error_reason"` which shows the raw JSON string. But `AiPromptService` outputs it raw too (line 65: `$step->error_reason ?? 'No error details.'`). This is inconsistent — sometimes it's parsed, sometimes not.
**Fix:** Either (a) store `error_reason` as a JSON column with an `array` cast (like the payloads), or (b) store it as structured array and cast it in the model. This makes the data consistently accessible as `$step->error_reason['message']` everywhere.

---

### 34. `show.blade.php` inlines all JavaScript — no CSP compatibility
**File:** `resources/views/show.blade.php` lines 237-326, `resources/views/layout.blade.php` lines 16-38
**Problem:** The dashboard uses inline `<script>` blocks and inline `style` attributes everywhere. Applications with a Content Security Policy (CSP) that blocks `unsafe-inline` will break the dashboard completely.
**Fix:** Move all JavaScript to an external `.js` file and all custom styles to an external `.css` file. Use `nonce` support or publish as static assets.

---

### 35. `TraceBar` component fires a DB query on every page load via `$trace->steps()->count()`
**File:** `resources/views/components/trace-bar.blade.php` line 17
**Problem:** `{{ $trace->steps()->count() }}` fires a `SELECT COUNT(*)` on every page load that has the trace bar enabled. In development this runs on every request.
**Fix:** Use `{{ $trace->steps->count() }}` (loaded collection) or `{{ $trace->loadCount('steps')->steps_count }}` to avoid the extra query.

---

### 36. No rate limiting on the MCP / JSON-RPC endpoint
**File:** `routes/api.php`
**Problem:** The MCP endpoint has no rate limiting. An attacker (or a misconfigured AI agent) can call `trigger_replay` in a tight loop, hammering both TraceReplay's database and the target application with replayed requests.
**Fix:** Add Laravel's `throttle` middleware to the API routes (e.g., `throttle:60,1`). Make it configurable via `api_rate_limit` config key.

---

### 37. `ReplayService::generateDiff()` doesn't handle nested arrays consistently
**File:** `src/Services/ReplayService.php` lines 69-95
**Problem:** When comparing `$original[$key]` and `$replay[$key]`, the method only recurses if *both* are arrays. If one is an array and the other is a scalar (e.g., the API changed a field from `"status": "ok"` to `"status": {"code": 200}`), it produces `['status' => 'changed', 'original' => 'ok', 'replay' => [...]]` — the replay value is an array dumped as-is without structural annotation.
**Fix:** Add a type-mismatch case: `if (is_array($value) !== is_array($replay[$key]))` → produce `['status' => 'type_changed', 'original_type' => gettype($value), 'replay_type' => gettype($replay[$key]), ...]`.

---

### 38. `CommandTraceListener` checks config twice — redundant and inconsistent
**File:** `src/Listeners/CommandTraceListener.php` lines 13, 37
**Problem:** Both `onCommandStarting()` and `onCommandFinished()` independently check `config('trace-replay.auto_trace.commands')` and the exclude list. But the listeners are only registered in `TraceReplayServiceProvider` *when the config is true* (line 76). The redundant check is dead code and adds confusion. Worse, the exclude-list logic is duplicated.
**Fix:** Remove the redundant config check from the listeners (the provider already gates registration). Extract the exclude-list check into a private `shouldTrace(string $command): bool` method to DRY up the logic.

---

### 39. `JobTraceListener` also double-checks config — same issue
**File:** `src/Listeners/JobTraceListener.php` lines 14, 33, 43
**Problem:** Same pattern as #38. The provider gates listener registration at boot time, but each listener method re-checks `config('trace-replay.auto_trace.jobs')`.
**Fix:** Remove the redundant checks. The provider already handles this.

---

## 🟢 Nice to Have — Long-Term Roadmap

| # | Idea | Why it matters |
|---|------|----------------|
| 40 | **Cache query tracking** (see #6 for critical version) | Track `Cache::get()`, `Cache::put()`, `Cache::forget()` per step — display hit/miss ratio in the waterfall |
| 41 | **Redis storage driver** | Avoid writing to the primary DB under high traffic; prune automatically with TTL |
| 42 | **`TraceReplay::group()` for parallel steps** | Model concurrent work (e.g., `Http::pool()`, `Promise::all`) accurately in the timeline |
| 43 | **Livewire / Inertia dashboard** | Real-time trace streaming without polling; modern developer expectation |
| 44 | **CLI `trace-replay:tail`** | Live tail of incoming traces in the terminal, à la `telescope:clear` but interactive |
| 45 | **Webhook / HTTP export on trace completion** | Push each trace to an external collector (Grafana Loki, Datadog, custom endpoint) |
| 46 | **Per-step custom metrics** | Allow `extra['metrics']['custom_score'] = 42` and render them in the timeline tooltip |
| 47 | **Scheduled job tracing** | Auto-trace `$schedule->command(...)` and `$schedule->call(...)` invocations |
| 48 | **Memory peak tracking** | Record `memory_get_peak_usage()` in addition to the delta; show the high-water mark per step |
| 49 | **Trace comparison** | Side-by-side comparison of two traces of the same endpoint to spot regressions |
| 50 | **Alert thresholds** | Notify when a trace exceeds a duration threshold (e.g., > 5 s) — not just on errors |
| 51 | **`TraceReplay::span()` for sub-step nesting** | Allow nested spans within a step for finer granularity without a full trace |
| 52 | **Dark/light mode toggle on dashboard** | Some developers prefer light mode; the current dashboard is dark-only |
| 53 | **Trace retention by status** | Different retention periods for error vs. success traces (keep errors 90 days, success 7 days) |
| 54 | **Payload search** | Full-text search within request/response payloads from the dashboard |
| 55 | **Auto-trace Livewire component hydrations** | Track Livewire component lifecycle as steps — unique to TraceReplay |

---

## Summary — Priority Matrix

| Priority | Count | Impact |
|----------|-------|--------|
| 🔴 Critical | 7 | Security, data corruption, production breakage |
| 🟠 High-Impact | 9 | Performance, DX, market competitiveness |
| 🟡 Medium | 14 | Differentiation, code quality, enterprise readiness |
| 🟢 Roadmap | 16 | Long-term moat and community growth |
| **Total** | **46** | |
