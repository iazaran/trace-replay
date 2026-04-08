<?php

namespace TraceReplay\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
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
        $allowedIps = config('trace-replay.allowed_ips', []);

        if (! empty($allowedIps) && ! \in_array($request->ip(), $allowedIps, true)) {
            abort(403, 'Access to TraceReplay dashboard is restricted by IP allowlist.');
        }

        if (\Illuminate\Support\Facades\Gate::has('view-trace-replay') && ! \Illuminate\Support\Facades\Gate::allows('view-trace-replay')) {
            abort(403, 'Unauthorized to view TraceReplay dashboard.');
        }

        return $next($request);
    }
}
