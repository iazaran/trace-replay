<?php

namespace TraceReplay\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use TraceReplay\Models\Trace;
use TraceReplay\Models\TraceStep;
use TraceReplay\Services\AiPromptService;
use TraceReplay\Services\ReplayService;

class McpController extends Controller
{
    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            $token = config('trace-replay.api.token');

            if (! $token) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Trace-Replay API token is not configured. Set TRACE_REPLAY_API_TOKEN to enable API access.',
                ], 403);
            }

            if (! hash_equals('Bearer '.$token, $request->header('Authorization', ''))) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Unauthorized: Invalid or missing API token.',
                ], 401);
            }

            return $next($request);
        });
    }

    public function listTraces(Request $request): JsonResponse
    {
        $query = Trace::withCount('steps')->orderBy('started_at', 'desc');

        if ($status = $request->query('status')) {
            if (! $this->isAllowedStatus($status)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Invalid status. Use success, error, or processing.',
                ], 422);
            }

            $query->where('status', $status);
        }

        if ($request->boolean('filter_by_error')) {
            $query->where('status', 'error');
        }

        return response()->json([
            'status' => 'success',
            'data' => $query->paginate($this->resolveLimit($request->query('limit'))),
        ]);
    }

    public function getContext(Request $request, string $id): JsonResponse
    {
        $stepLimit = $this->resolveStepLimit($request->query('step_limit'));
        $trace = $this->loadTraceContext($id, $stepLimit);
        $errorStep = $this->resolveErrorStep($trace);

        return response()->json([
            'status' => 'success',
            'data' => [
                'trace' => $trace,
                'completion_percentage' => $this->resolveCompletionPercentage($trace, $errorStep),
                'total_duration' => $trace->duration_ms,
                'error_step' => $errorStep,
                'step_limit' => $stepLimit,
                'steps_returned' => $trace->steps->count(),
            ],
        ]);
    }

    public function triggerReplay(Request $request, string $id, ReplayService $replayService): JsonResponse
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

    public function generateFixPrompt(string $id, AiPromptService $promptService): JsonResponse
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
    public function handleRpc(Request $request, ReplayService $replayService, AiPromptService $promptService): JsonResponse
    {
        $method = $request->input('method');
        $params = $request->input('params', []);

        try {
            switch ($method) {
                case 'list_traces':
                    $query = Trace::withCount('steps')->orderBy('started_at', 'desc');
                    if (isset($params['status'])) {
                        if (! $this->isAllowedStatus($params['status'])) {
                            throw new \InvalidArgumentException('Invalid status. Use success, error, or processing.', -32602);
                        }

                        $query->where('status', $params['status']);
                    }
                    if (isset($params['filter_by_error']) && $params['filter_by_error']) {
                        $query->where('status', 'error');
                    }
                    $result = $query->paginate($this->resolveLimit($params['limit'] ?? null))->toArray();
                    break;

                case 'get_trace_context':
                    $stepLimit = $this->resolveStepLimit($params['step_limit'] ?? null);
                    $trace = $this->loadTraceContext($params['trace_id'], $stepLimit);
                    $errorStep = $this->resolveErrorStep($trace);
                    $result = [
                        'trace' => $trace,
                        'completion_percentage' => $this->resolveCompletionPercentage($trace, $errorStep),
                        'error_step' => $errorStep,
                        'step_limit' => $stepLimit,
                        'steps_returned' => $trace->steps->count(),
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

    protected function resolveLimit(mixed $limit): int
    {
        if ($limit === null || $limit === '' || is_array($limit)) {
            return 20;
        }

        return min(max((int) $limit, 1), 100);
    }

    protected function resolveStepLimit(mixed $limit): int
    {
        $max = max((int) config('trace-replay.api.max_steps', 500), 1);

        if ($limit === null || $limit === '' || is_array($limit)) {
            return $max;
        }

        return min(max((int) $limit, 1), $max);
    }

    protected function loadTraceContext(string $id, int $stepLimit): Trace
    {
        $trace = Trace::findOrFail($id);
        $trace->setRelation('steps', $trace->steps()
            ->orderBy('step_order')
            ->limit($stepLimit)
            ->get());

        return $trace;
    }

    protected function resolveErrorStep(Trace $trace): ?TraceStep
    {
        return $trace->steps()
            ->where('status', 'error')
            ->orderBy('step_order')
            ->first();
    }

    protected function resolveCompletionPercentage(Trace $trace, ?TraceStep $errorStep): int
    {
        if ($trace->status === 'success') {
            return 100;
        }

        $totalSteps = $trace->steps()->where('type', '!=', 'checkpoint')->count();

        if ($totalSteps === 0) {
            return 0;
        }

        if ($errorStep) {
            $completedSteps = $trace->steps()
                ->where('type', '!=', 'checkpoint')
                ->where('step_order', '<', $errorStep->step_order)
                ->count();

            return (int) round(($completedSteps / $totalSteps) * 100);
        }

        return 50;
    }

    protected function isAllowedStatus(mixed $status): bool
    {
        return \is_string($status) && \in_array($status, ['success', 'error', 'processing'], true);
    }
}
