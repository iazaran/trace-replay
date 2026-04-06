<?php

namespace TraceReplay\Console\Commands;

use Illuminate\Console\Command;
use TraceReplay\Models\Trace;

class ExportTraceCommand extends Command
{
    protected $signature = 'trace-replay:export
                            {id? : UUID of the trace to export (omit to export all)}
                            {--format=json : Export format: json or csv}
                            {--output= : File path to write the output (defaults to stdout)}
                            {--status= : Filter by status when exporting all (success|error|processing)}';

    protected $description = 'Export one or all TraceReplay traces to JSON or CSV.';

    public function handle(): int
    {
        $id = $this->argument('id');
        $format = strtolower($this->option('format'));
        $output = $this->option('output');
        $status = $this->option('status');

        if (! \in_array($format, ['json', 'csv'], true)) {
            $this->error("Unsupported format '{$format}'. Use 'json' or 'csv'.");

            return self::FAILURE;
        }

        if ($status && ! \in_array($status, ['success', 'error', 'processing'], true)) {
            $this->error("Invalid status '{$status}'. Use 'success', 'error', or 'processing'.");

            return self::FAILURE;
        }

        if ($id) {
            $traces = Trace::with('steps')->where('id', $id)->get();
            if ($traces->isEmpty()) {
                $this->error("Trace '{$id}' not found.");

                return self::FAILURE;
            }
        } else {
            $query = Trace::with('steps');
            if ($status) {
                $query->where('status', $status);
            }
            $traces = $query->orderBy('started_at', 'desc')->get();
        }

        $content = $format === 'json'
            ? $this->toJson($traces)
            : $this->toCsv($traces);

        if ($output) {
            $dir = \dirname($output);
            if ($dir && ! \is_dir($dir)) {
                $this->error("Directory '{$dir}' does not exist.");

                return self::FAILURE;
            }
            if (@file_put_contents($output, $content) === false) {
                $this->error("Failed to write to '{$output}'.");

                return self::FAILURE;
            }
            $this->info("Exported {$traces->count()} trace(s) to {$output}");
        } else {
            $this->line($content);
        }

        return self::SUCCESS;
    }

    private function toJson($traces): string
    {
        return json_encode($traces->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function toCsv($traces): string
    {
        $rows = [];
        $rows[] = implode(',', ['id', 'name', 'status', 'duration_ms', 'steps', 'started_at', 'completed_at']);

        foreach ($traces as $trace) {
            $rows[] = implode(',', [
                $trace->id,
                '"'.str_replace('"', '""', $trace->name ?? '').'"',
                $trace->status,
                $trace->duration_ms ?? 0,
                $trace->steps->count(),
                '"'.($trace->started_at ?? '').'"',
                '"'.($trace->completed_at ?? '').'"',
            ]);
        }

        return implode("\n", $rows);
    }
}
