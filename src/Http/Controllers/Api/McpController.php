<?php

namespace TraceReplay\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use TraceReplay\Models\Trace;
use TraceReplay\Services\AiPromptService;
use TraceReplay\Services\ReplayService;

class McpController extends Controller
{
    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            $token = config('trace-replay.api.token');

            if ($token && $request->header('Authorization') !== 'Bearer '.$token) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Unauthorized: Invalid or missing API token.',
                ], 401);
            }

            // If token is NOT set, we allow it ONLY if it's explicitly disabled/enabled?
            // Recommendation 15 says "Default the API to disabled unless the token is set."
            if (! $token) {
                 return response()->json([
                    'status' => 'error',
                    'message' => 'API is disabled. Please set TRACE_REPLAY_API_TOKEN in your .env.',
                ], 403);
            }

            return $next($request);
        });
    }
    public function listTraces(Request $request)
    {
        $query = Trace::withCount('steps')->orderBy('started_at', 'desc');

        if ($request->boolean('filter_by_error')) {
            $query->where('status', 'error');
        }

        return response()->json([
            'status' => 'success',
            'data' => $query->paginate(20),
        ]);
    }

    public function getContext($id)
    {
        $trace = Trace::with('steps')->findOrFail($id);

        return response()->json([
            'status' => 'success',
            'data' => [
                'trace' => $trace,
                'completion_percentage' => $trace->completion_percentage,
                'total_duration' => $trace->duration_ms,
                'error_step' => $trace->error_step,
            ],
        ]);
    }

    public function triggerReplay(Request $request, $id, ReplayService $replayService)
    {
        $trace = Trace::with('steps')->findOrFail($id);

        try {
            $overrideUrl = $request->input('override_url');
            $result = $replayService->replay($trace, $overrideUrl);

            return response()->json([
                'status' => 'success',
                'data' => $result,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    public function generateFixPrompt($id, AiPromptService $promptService)
    {
        $trace = Trace::with('steps')->findOrFail($id);

        return response()->json([
            'status' => 'success',
            'data' => [
                'prompt' => $promptService->generateFixPrompt($trace),
            ],
        ]);
    }

    /**
     * Optional JSON-RPC 2.0 handler
     */
    public function handleRpc(Request $request, ReplayService $replayService, AiPromptService $promptService)
    {
        $method = $request->input('method');
        $params = $request->input('params', []);

        try {
            switch ($method) {
                case 'list_traces':
                    $query = Trace::withCount('steps')->orderBy('started_at', 'desc');
                    if (isset($params['filter_by_error']) && $params['filter_by_error']) {
                        $query->where('status', 'error');
                    }
                    $result = $query->paginate(20)->toArray();
                    break;

                case 'get_trace_context':
                    $trace = Trace::with('steps')->findOrFail($params['trace_id']);
                    $result = [
                        'trace' => $trace,
                        'completion_percentage' => $trace->completion_percentage,
                        'error_step' => $trace->error_step,
                    ];
                    break;

                case 'trigger_replay':
                    $trace = Trace::with('steps')->findOrFail($params['trace_id']);
                    $result = $replayService->replay($trace, $params['override_url'] ?? null);
                    break;

                case 'generate_fix_prompt':
                    $trace = Trace::with('steps')->findOrFail($params['trace_id']);
                    $result = ['prompt' => $promptService->generateFixPrompt($trace)];
                    break;

                default:
                    throw new \Exception('Method not found', -32601);
            }

            return response()->json([
                'jsonrpc' => '2.0',
                'result' => $result,
                'id' => $request->input('id'),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'jsonrpc' => '2.0',
                'error' => [
                    'code' => \is_int($e->getCode()) && $e->getCode() !== 0 ? $e->getCode() : -32000,
                    'message' => $e->getMessage(),
                ],
                'id' => $request->input('id'),
            ]);
        }
    }
}
