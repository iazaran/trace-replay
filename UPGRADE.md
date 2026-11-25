# Upgrade Guide

This document lists behavioral changes between TraceReplay versions that may
require action when upgrading existing applications. Bug fixes and additive
features are not listed here.

## Upgrading to 1.3.2 from 1.3.x

1.3.2 is a drop-in patch release: no migrations, no required config changes.
It adds PHP 8.5 to the tested matrix and fixes the items below, some of which
change what you will observe after updating.

### 1. Laravel's default exception logging works again

Since 1.2.0 the exception-capture hook returned `false` from its
`reportable()` callback. In Laravel that means "stop", so exceptions were
recorded on the active trace but never reached your default log channel, and
`reportable()` callbacks registered after TraceReplay did not run. The hook no
longer stops propagation.

**What you will see:** reported exceptions appear in your logs (and any
reporters registered after TraceReplay run) exactly as they would without the
package.

### 2. Livewire 3 and 4 checkpoints are recorded

`auto_trace.livewire` (on by default) only listened for Livewire 2 event
names, so it never fired on Livewire 3 or 4. It now listens for `hydrate` and
`dehydrate` on Livewire 3+ and keeps the old names on Livewire 2.

**What you will see:** traces of Livewire requests gain
`Livewire Hydrate: …` / `Livewire Dehydrate: …` checkpoints, labelled with the
component class (or, for anonymous single-file components, the component
name). Set `TRACE_REPLAY_AUTO_TRACE_LIVEWIRE=false` to keep the previous
output.

### 3. AI drivers

A model you have set with `TRACE_REPLAY_AI_MODEL` keeps working as before.
The changes affect the defaults:

- `ai.model` now defaults to empty, and each driver picks its own default:
  `gpt-4o` (OpenAI, unchanged), `claude-opus-5-5` (Anthropic) and `llama3`
  (Ollama). Previously `gpt-4o` was sent to every provider, so the Anthropic
  and Ollama drivers failed unless a model was set. The Anthropic driver also
  ignores (and logs) a non-`claude` model, such as the `gpt-4o` default in configs
  published before 1.3.2. With Ollama and an older published config, set
  `TRACE_REPLAY_AI_MODEL` explicitly.
- The Anthropic driver reads the answer from the response's text blocks
  (current models return thinking blocks first) and returns no answer on a
  refusal. Claude 5-generation, Fable and Mythos models think by default and
  get up to 16,000 output tokens; on those, 1.3.1 never returned an answer
  because it read the first content block. Earlier models (for example
  `claude-sonnet-4-5`) are sent exactly as before, with a 1,024-token cap.
  The new optional `ai.max_tokens` (`TRACE_REPLAY_AI_MAX_TOKENS`) overrides
  either cap. On models that support it (`claude-opus-5-5`, `claude-opus-5`,
  `claude-sonnet-5-5`, `claude-fable-5-1`, `claude-fable-5-2`), the driver
  opts into Anthropic's server-side refusal fallback (`fallbacks: "default"`),
  which may answer a declined request from another model.
- The Ollama driver uses `http://localhost:11434/api/generate` when
  `ai.base_url` is empty. A configured URL is used unchanged.
- New optional `ai.timeout` (`TRACE_REPLAY_AI_TIMEOUT`, default 60 seconds,
  the previous fixed value). Configs published before 1.3.2 have no
  `timeout` key, so add `'timeout' => env('TRACE_REPLAY_AI_TIMEOUT', 60)`
  to the `ai` section to use the env variable.
- When the provider is unreachable or times out, the dashboard's AI endpoint
  still returns the generated prompt with `ai_response: null` instead of an
  HTTP 500. Failed calls are logged as warnings with the HTTP status or
  exception class only (no prompt, body or URL).

### 4. Smaller fixes

- Failure notification links honour `trace-replay.route_prefix` instead of
  always pointing at `/trace-replay`.
- Dashboard `allowed_ips` entries are trimmed before matching, so
  `TRACE_REPLAY_ALLOWED_IPS="10.0.0.1, 10.0.0.2"` allows both addresses. A
  non-empty allowlist still denies every address it does not list.
- `TraceReplayTenantMiddleware` ignores array values such as
  `?tr_workspace[]=x` instead of failing the request.
- Batch-inserted steps use the model's own UUID generator, matching the ids
  of steps saved one at a time.

## Upgrading to 1.3.0 from 1.2.x

The 1.3.0 release tightens privacy and replay safety defaults. Existing
applications continue to work without configuration changes, but the items
below change observable behavior.

### 1. Workspace and project IDs reset after every trace

`TraceReplay::setWorkspaceId()` and `TraceReplay::setProjectId()` now reset
inside the `end()` lifecycle to prevent identifier leakage across requests
in Octane, Swoole, and RoadRunner. If you previously set these once at
application boot, the value will no longer persist into later traces.

**Action:** move the calls into a request-scoped location such as a
middleware that runs before `TraceMiddleware`.

```php
// app/Http/Middleware/SetTraceWorkspace.php
public function handle(Request $request, Closure $next)
{
    TraceReplay::setWorkspaceId($request->user()?->workspace_id);

    return $next($request);
}
```

### 2. Queue job payloads are no longer captured by default

`auto_trace.capture_job_payload` defaults to `false`. The "Job Started"
checkpoint records `queue` and `job_id` but omits the serialized job
payload, which often contains application secrets.

**Action:** set `TRACE_REPLAY_CAPTURE_JOB_PAYLOAD=true` to restore the
previous behavior. Leave it off in production unless you control the data
inside your job payloads.

### 3. Replay strips sensitive request headers

`Authorization`, `Cookie`, `Proxy-Authorization`, `X-CSRF-Token`,
`X-XSRF-Token`, `CSRF-Token`, `Forwarded`, and any `X-Forwarded-*` headers
are removed from replayed requests. Previously only `Host` and `Cookie`
were removed.

**Action:** if your replay target relied on the recorded `Authorization`
header, configure the target to accept un-authenticated replays or expose
a dedicated replay endpoint guarded by IP allowlisting.

### 4. Replay host allowlist enforced for `override_url`

Passing an `override_url` to `ReplayService::replay()` (used by the
dashboard "Replay" button and the MCP `trigger_replay` method) now rejects
hosts that are not in `trace-replay.replay.allowed_hosts`. When the
allowlist is empty, the override must match the originally recorded host.

**Action:** set the allowlist when you need to replay against a different
host, for example a staging environment:

```env
TRACE_REPLAY_REPLAY_ALLOWED_HOSTS=staging.example.com,*.internal.test
```

### 5. Other behavioral changes worth noting

- **State snapshot masking.** `state_snapshot`, `db_queries`, `cache_calls`,
  `http_calls`, `mail_calls`, `log_calls`, and `error_reason` are now run
  through `PayloadMasker` before storage. Values stored under any key
  listed in `mask_fields` (such as `token`, `secret`, `authorization`)
  appear as `********` in the dashboard.
- **Replay URI handling.** When `request_payload.uri` is a full URL, only
  its path and query are used; the scheme/host are taken from the replay
  base URL. Traces captured by `TraceMiddleware` are unaffected because
  Laravel records request paths, not full URLs.
- **Dashboard stats window.** `DashboardController::stats()` and the
  aggregate counters on the index page now reflect the last 30 days
  instead of all time. The JSON response shape is unchanged.
- **MCP `list_traces` validation.** Invalid values for the `status`
  parameter now return HTTP 422 instead of being silently ignored.
- **Prune command with `retention_days=null`.** `trace-replay:prune`
  exits 0 with an informational message when no `--days` flag is passed,
  instead of failing with a non-zero exit code.
- **Failure notifications dispatch.** When
  `trace-replay.notifications.on_failure` is enabled, mail and Slack
  notifications are dispatched after the response. With a synchronous
  queue connection they still run in the same PHP process after the
  response is sent; with an async queue connection they are pushed to
  your configured queue worker.
- **Sampling RNG.** `random_int` replaces `mt_rand` for sample-rate
  decisions. Distribution is equivalent for `0 < sample_rate < 1.0`.

### 6. New configuration keys

The following keys are new in 1.3.0. They are read with `config(key, default)`
so existing published configs continue to work, but you may want to add
them when you next publish the config:

```php
'route_prefix' => env('TRACE_REPLAY_ROUTE_PREFIX', 'trace-replay'),

'replay' => [
    // ...existing keys...
    'allowed_hosts' => array_filter(explode(',', env('TRACE_REPLAY_REPLAY_ALLOWED_HOSTS', ''))),
],

'api' => [
    // ...existing keys...
    'route_prefix' => env('TRACE_REPLAY_API_ROUTE_PREFIX', 'api/trace-replay'),
    'max_steps' => env('TRACE_REPLAY_API_MAX_STEPS', 500),
],

'ai' => [
    // ...existing keys...
    'base_url' => env('TRACE_REPLAY_AI_BASE_URL'),
],

'auto_trace' => [
    // ...existing keys...
    'capture_job_payload' => env('TRACE_REPLAY_CAPTURE_JOB_PAYLOAD', false),
],
```

Run `php artisan trace-replay:doctor` after upgrading to verify the new
defaults match your deployment.
