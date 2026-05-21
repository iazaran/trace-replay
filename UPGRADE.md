# Upgrade Guide

This document lists behavioral changes between TraceReplay versions that may
require action when upgrading existing applications. Bug fixes and additive
features are not listed here.

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
