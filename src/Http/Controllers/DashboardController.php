<?php

namespace TraceReplay\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use TraceReplay\Models\Trace;
use TraceReplay\Services\AiPromptService;
use TraceReplay\Services\ReplayService;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $query = Trace::withCount('steps')->orderBy('started_at', 'desc');

        $status = $request->query('status');
        if ($status && \in_array($status, ['success', 'error', 'processing'], true)) {
            $query->where('status', $status);
        }

        if ($search = $request->query('search')) {
            $query->search($search);
        }

        $traces = $query->paginate(25)->withQueryString();

        return view('trace-replay::index', compact('traces'));
    }

    public function show(string $id)
    {
        $trace = Trace::with('steps')->findOrFail($id);

        return view('trace-replay::show', compact('trace'));
    }

    public function replay(Request $request, string $id, ReplayService $replayService): JsonResponse
    {
        $trace = Trace::with('steps')->findOrFail($id);

        try {
            $result = $replayService->replay($trace, $request->input('override_url'));

            return response()->json(['status' => 'success', 'data' => $result]);
        } catch (\Throwable $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 400);
        }
    }

    public function generatePrompt(string $id, AiPromptService $promptService): JsonResponse
    {
        $trace = Trace::with('steps')->findOrFail($id);
        $prompt = $promptService->generateFixPrompt($trace);

        // Call the configured AI driver (OpenAI, Anthropic, or Ollama)
        $aiResponse = $promptService->callAi($prompt);

        return response()->json([
            'status' => 'success',
            'data' => [
                'prompt' => $prompt,
                'ai_response' => $aiResponse,   // null when no key is configured
            ],
        ]);
    }

    public function stats(): JsonResponse
    {
        $stats = Trace::selectRaw('
            count(*) as total,
            count(case when status = "error" then 1 end) as failed,
            count(case when status = "success" then 1 end) as success,
            avg(duration_ms) as avg_duration,
            max(duration_ms) as slowest
        ')->first();

        $today = Trace::whereDate('started_at', now()->today())->count();

        return response()->json([
            'total' => (int) $stats->total,
            'success' => (int) $stats->success,
            'failed' => (int) $stats->failed,
            'today' => $today,
            'failure_rate' => $stats->total > 0 ? round(($stats->failed / $stats->total) * 100, 1) : 0,
            'avg_duration' => round($stats->avg_duration ?? 0, 2),
            'slowest' => round($stats->slowest ?? 0, 2),
        ]);
    }

    public function export(string $id)
    {
        $trace = Trace::with('steps')->findOrFail($id);

        $filename = 'trace-'.substr($id, 0, 8).'.json';
        $content = json_encode($trace->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return response($content, 200, [
            'Content-Type' => 'application/json',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}
