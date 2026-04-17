<?php

namespace TraceReplay\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use TraceReplay\Database\Factories\TraceFactory;

class Trace extends Model
{
    use HasFactory, HasUuids;

    protected static function newFactory(): TraceFactory
    {
        return TraceFactory::new();
    }

    protected $table = 'tr_traces';

    protected $fillable = [
        'workspace_id',
        'project_id',
        'name',
        'type',
        'tags',
        'trace_parent',
        'duration_ms',
        'peak_memory_usage',
        'status',
        'http_status',
        'error_reason',
        'user_id',
        'user_type',
        'ip_address',
        'user_agent',
        'started_at',
        'completed_at',
    ];

    public const TYPE_HTTP = 'http';

    public const TYPE_JOB = 'job';

    public const TYPE_COMMAND = 'command';

    public const TYPE_SCHEDULE = 'schedule';

    protected $casts = [
        'tags' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'duration_ms' => 'decimal:2',
        'http_status' => 'integer',
        'error_reason' => 'array',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function workspace()
    {
        return $this->belongsTo(Workspace::class, 'workspace_id');
    }

    public function steps()
    {
        return $this->hasMany(TraceStep::class, 'trace_id')->orderBy('step_order');
    }

    // ── Scopes ──────────────────────────────────────────────────────────────

    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', 'error');
    }

    public function scopeSuccessful(Builder $query): Builder
    {
        return $query->where('status', 'success');
    }

    public function scopeSearch(Builder $query, string $term): Builder
    {
        return $query->where(function (Builder $q) use ($term) {
            $q->where('name', 'like', "%{$term}%")
                ->orWhere('user_id', 'like', "%{$term}%")
                ->orWhere('ip_address', 'like', "%{$term}%");
        });
    }

    // ── Accessors ────────────────────────────────────────────────────────────

    public function getCompletionPercentageAttribute(): int
    {
        if ($this->status === 'success') {
            return 100;
        }

        $steps = $this->relationLoaded('steps')
            ? $this->steps->sortBy('step_order')->values()
            : $this->steps()->orderBy('step_order')->get();

        $nonCheckpointSteps = $steps->where('type', '!=', 'checkpoint')->values();
        $totalSteps = $nonCheckpointSteps->count();
        if ($totalSteps === 0) {
            return 0;
        }

        $errorStep = $steps->firstWhere('status', 'error');
        if ($errorStep) {
            $completedSteps = $nonCheckpointSteps
                ->filter(fn (TraceStep $step) => $step->step_order < $errorStep->step_order)
                ->count();

            return (int) round(($completedSteps / $totalSteps) * 100);
        }

        return 50;
    }

    public function getErrorStepAttribute(): ?TraceStep
    {
        if ($this->relationLoaded('steps')) {
            return $this->steps->firstWhere('status', 'error');
        }

        return $this->steps()->where('status', 'error')->first();
    }

    public function getTotalDbQueriesAttribute(): int
    {
        if ($this->relationLoaded('steps')) {
            return (int) $this->steps->sum('db_query_count');
        }

        return (int) $this->steps()->sum('db_query_count');
    }

    public function getTotalMemoryUsageAttribute(): int
    {
        if ($this->relationLoaded('steps')) {
            return (int) $this->steps->sum('memory_usage');
        }

        return (int) $this->steps()->sum('memory_usage');
    }
}
