<?php

namespace TraceReplay\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use TraceReplay\Facades\TraceReplay;

class TraceReplayTenantMiddleware
{
    /**
     * Handle an incoming request.
     * Sets the workspace/project scoping based on headers or parameters.
     */
    public function handle(Request $request, Closure $next)
    {
        // Allow setting workspace/project via headers for API usage
        if ($workspace = $request->header('X-Trace-Replay-Workspace')) {
            TraceReplay::setWorkspaceId($workspace);
        }

        if ($project = $request->header('X-Trace-Replay-Project')) {
            TraceReplay::setProjectId($project);
        }

        // Also allow via URL parameter for internal dashboard usage if nested
        if ($request->has('tr_workspace')) {
            TraceReplay::setWorkspaceId($request->input('tr_workspace'));
        }

        if ($request->has('tr_project')) {
            TraceReplay::setProjectId($request->input('tr_project'));
        }

        return $next($request);
    }
}
