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
        'project_id',
        'name',
        'tags',
        'duration_ms',
        'status',
        'http_status',
        'user_id',
        'user_type',
        'ip_address',
        'user_agent',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'tags' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'duration_ms' => 'float',
        'http_status' => 'integer',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class, 'project_id');
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

        $totalSteps = $this->steps()->where('type', '!=', 'checkpoint')->count();
        if ($totalSteps === 0) {
            return 0;
        }

        $errorStep = $this->steps()->where('status', 'error')->first();
        if ($errorStep) {
            return (int) round((($errorStep->step_order - 1) / $totalSteps) * 100);
        }

        return 50;
    }

    public function getErrorStepAttribute(): ?TraceStep
    {
        return $this->steps()->where('status', 'error')->first();
    }

    public function getTotalDbQueriesAttribute(): int
    {
        return (int) $this->steps()->sum('db_query_count');
    }

    public function getTotalMemoryUsageAttribute(): int
    {
        return (int) $this->steps()->sum('memory_usage');
    }
}
