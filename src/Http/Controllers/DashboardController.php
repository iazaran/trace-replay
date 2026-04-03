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

        return view('tracereplay::index', compact('traces'));
    }

    public function show(string $id)
    {
        $trace = Trace::with('steps')->findOrFail($id);

        return view('tracereplay::show', compact('trace'));
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
        $trace  = Trace::with('steps')->findOrFail($id);
        $prompt = $promptService->generateFixPrompt($trace);

        // If OpenAI key is configured, attempt a direct API call
        $aiResponse = $promptService->callOpenAI($prompt);

        return response()->json([
            'status' => 'success',
            'data'   => [
                'prompt'      => $prompt,
                'ai_response' => $aiResponse,   // null when no key is configured
            ],
        ]);
    }

    public function stats(): JsonResponse
    {
        $total   = Trace::count();
        $failed  = Trace::failed()->count();
        $success = Trace::successful()->count();
        $today   = Trace::whereDate('started_at', today())->count();

        $avgDuration = Trace::whereNotNull('duration_ms')->avg('duration_ms');
        $slowest     = Trace::whereNotNull('duration_ms')->max('duration_ms');

        return response()->json([
            'total'        => $total,
            'success'      => $success,
            'failed'       => $failed,
            'today'        => $today,
            'failure_rate' => $total > 0 ? round(($failed / $total) * 100, 1) : 0,
            'avg_duration' => round($avgDuration ?? 0, 2),
            'slowest'      => round($slowest ?? 0, 2),
        ]);
    }

    public function export(string $id)
    {
        $trace = Trace::with('steps')->findOrFail($id);

        $filename = 'trace-' . substr($id, 0, 8) . '.json';
        $content  = json_encode($trace->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return response($content, 200, [
            'Content-Type'        => 'application/json',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}

