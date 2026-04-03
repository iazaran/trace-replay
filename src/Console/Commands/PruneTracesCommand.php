<?php

namespace TraceReplay\Console\Commands;

use Illuminate\Console\Command;
use TraceReplay\Models\Trace;

class PruneTracesCommand extends Command
{
    protected $signature = 'tracereplay:prune
                            {--days= : Override the retention_days config value}
                            {--status= : Only prune traces with this status (success|error|processing)}
                            {--dry-run : Show what would be deleted without deleting}';

    protected $description = 'Delete TraceReplay traces older than the configured retention period.';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('tracereplay.retention_days', 30));
        $status = $this->option('status');
        $dryRun = $this->option('dry-run');

        if ($days <= 0) {
            $this->error('Retention days must be a positive integer.');

            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);

        if ($status && ! \in_array($status, ['success', 'error', 'processing'], true)) {
            $this->error("Invalid status '{$status}'. Use 'success', 'error', or 'processing'.");

            return self::FAILURE;
        }

        $query = Trace::where('started_at', '<', $cutoff);

        if ($status) {
            $query->where('status', $status);
        }

        $count = $query->count();

        if ($count === 0) {
            $this->info('No traces found matching the criteria.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->warn("[Dry Run] Would delete {$count} trace(s) older than {$days} day(s).");

            return self::SUCCESS;
        }

        $query->delete();

        $this->info("Deleted {$count} trace(s) older than {$days} day(s).");

        return self::SUCCESS;
    }
}
