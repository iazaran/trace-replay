<?php

namespace TraceReplay\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use TraceReplay\Database\Factories\TraceStepFactory;

class TraceStep extends Model
{
    use HasFactory, HasUuids;

    protected static function newFactory(): TraceStepFactory
    {
        return TraceStepFactory::new();
    }

    protected $table = 'tr_trace_steps';

    protected $fillable = [
        'trace_id',
        'label',
        'type',
        'step_order',
        'request_payload',
        'response_payload',
        'state_snapshot',
        'duration_ms',
        'memory_usage',
        'db_query_count',
        'db_query_time_ms',
        'db_queries',
        'cache_calls',
        'cache_hit_count',
        'cache_miss_count',
        'http_calls',
        'mail_calls',
        'log_calls',
        'status',
        'error_reason',
    ];

    protected $casts = [
        'request_payload' => 'array',
        'response_payload' => 'array',
        'state_snapshot' => 'array',
        'duration_ms' => 'decimal:2',
        'db_query_time_ms' => 'decimal:2',
        'memory_usage' => 'integer',
        'db_query_count' => 'integer',
        'db_queries' => 'array',
        'cache_calls' => 'array',
        'cache_hit_count' => 'integer',
        'cache_miss_count' => 'integer',
        'http_calls' => 'array',
        'mail_calls' => 'array',
        'log_calls' => 'array',
        'error_reason' => 'array',
    ];

    public function trace()
    {
        return $this->belongsTo(Trace::class, 'trace_id');
    }

    public function getDurationColorAttribute(): string
    {
        $ms = $this->duration_ms ?? 0;
        if ($ms < 50) {
            return 'green';
        }
        if ($ms < 200) {
            return 'yellow';
        }
        if ($ms < 1000) {
            return 'orange';
        }

        return 'red';
    }
}
