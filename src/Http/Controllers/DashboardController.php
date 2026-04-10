<?php

namespace TraceReplay\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use TraceReplay\Models\Trace;
use TraceReplay\Models\TraceStep;
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

        $type = $request->query('type');
        if ($type && \in_array($type, ['http', 'job', 'command', 'schedule'], true)) {
            $query->where('type', $type);
        }

        // Date range filter
        $dateRange = $request->query('date_range');
        if ($dateRange) {
            $query->where(function ($q) use ($dateRange) {
                match ($dateRange) {
                    'today' => $q->whereDate('started_at', now()->toDateString()),
                    'yesterday' => $q->whereDate('started_at', now()->subDay()->toDateString()),
                    '7days' => $q->where('started_at', '>=', now()->subDays(7)),
                    '30days' => $q->where('started_at', '>=', now()->subDays(30)),
                    'hour' => $q->where('started_at', '>=', now()->subHour()),
                    default => null,
                };
            });
        }

        if ($search = $request->query('search')) {
            $query->search($search);
        }

        $traces = $query->paginate(25)->withQueryString();

        // Dashboard stats
        $stats = $this->getDashboardStats();

        return view('trace-replay::index', compact('traces', 'stats'));
    }

    protected function getDashboardStats(): array
    {
        $today = now()->startOfDay();
        $lastHour = now()->subHour();

        // General stats
        $totals = Trace::selectRaw("
            COUNT(*) as total,
            SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as success,
            SUM(CASE WHEN status = 'error' THEN 1 ELSE 0 END) as errors,
            SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as processing,
            AVG(duration_ms) as avg_duration
        ")->first();

        // By type
        $byType = Trace::selectRaw('type, COUNT(*) as count')
            ->whereDate('started_at', '>=', now()->subDays(7))
            ->groupBy('type')
            ->pluck('count', 'type')
            ->toArray();

        // Last 7 days trend
        $trend = Trace::selectRaw("
            DATE(started_at) as date,
            COUNT(*) as total,
            SUM(CASE WHEN status = 'error' THEN 1 ELSE 0 END) as errors
        ")
            ->whereDate('started_at', '>=', now()->subDays(7))
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(fn ($row) => [
                'date' => $row->date,
                'total' => (int) $row->total,
                'errors' => (int) $row->errors,
            ])
            ->values()
            ->toArray();

        // Last hour count
        $lastHourCount = Trace::where('started_at', '>=', $lastHour)->count();
        $todayCount = Trace::where('started_at', '>=', $today)->count();

        // Operations breakdown (last 7 days)
        $operations = TraceStep::join('tr_traces', 'tr_trace_steps.trace_id', '=', 'tr_traces.id')
            ->whereDate('tr_traces.started_at', '>=', now()->subDays(7))
            ->selectRaw("
                SUM(COALESCE(db_query_count, 0)) as db_queries,
                SUM(COALESCE(cache_hit_count, 0) + COALESCE(cache_miss_count, 0)) as cache_calls,
                SUM(CASE WHEN http_calls IS NOT NULL AND http_calls != '[]' AND http_calls != 'null' THEN 1 ELSE 0 END) as http_calls,
                SUM(CASE WHEN mail_calls IS NOT NULL AND mail_calls != '[]' AND mail_calls != 'null' THEN 1 ELSE 0 END) as mail_calls
            ")
            ->first();

        $operationsData = [
            'db_queries' => (int) ($operations->db_queries ?? 0),
            'cache_calls' => (int) ($operations->cache_calls ?? 0),
            'http_calls' => (int) ($operations->http_calls ?? 0),
            'mail_calls' => (int) ($operations->mail_calls ?? 0),
        ];

        return [
            'total' => (int) ($totals->total ?? 0),
            'success' => (int) ($totals->success ?? 0),
            'errors' => (int) ($totals->errors ?? 0),
            'processing' => (int) ($totals->processing ?? 0),
            'avg_duration' => round($totals->avg_duration ?? 0, 2),
            'error_rate' => $totals->total > 0 ? round(($totals->errors / $totals->total) * 100, 1) : 0,
            'by_type' => $byType,
            'operations' => $operationsData,
            'trend' => $trend,
            'last_hour' => $lastHourCount,
            'today' => $todayCount,
        ];
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
