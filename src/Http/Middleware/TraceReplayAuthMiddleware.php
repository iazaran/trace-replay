<?php

namespace TraceReplay\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the TraceReplay dashboard routes.
 *
 * - IP allowlist: if `trace-replay.allowed_ips` is non-empty, only those IPs can access the dashboard.
 * - Can be combined with any Laravel auth middleware via the `trace-replay.middleware` config key.
 */
class TraceReplayAuthMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $allowedIps = (array) config('trace-replay.allowed_ips', []);

        // Any configured entry turns the allowlist on, even one that is only
        // whitespace. Entries are trimmed so "10.0.0.1, 10.0.0.2" matches both.
        if (! empty($allowedIps) && ! \in_array($request->ip(), array_map(
            static fn ($ip) => \is_string($ip) ? trim($ip) : $ip,
            $allowedIps
        ), true)) {
            abort(403, 'Access to TraceReplay dashboard is restricted by IP allowlist.');
        }

        if (Gate::has('view-trace-replay') && ! Gate::allows('view-trace-replay')) {
            abort(403, 'Unauthorized to view TraceReplay dashboard.');
        }

        return $next($request);
    }
}
