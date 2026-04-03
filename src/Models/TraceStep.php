<?php

namespace TraceReplay\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class TraceStep extends Model
{
    use HasUuids;

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
        'status',
        'error_reason',
    ];

    protected $casts = [
        'request_payload'  => 'array',
        'response_payload' => 'array',
        'state_snapshot'   => 'array',
        'duration_ms'      => 'float',
        'db_query_time_ms' => 'float',
        'memory_usage'     => 'integer',
        'db_query_count'   => 'integer',
    ];

    public function trace()
    {
        return $this->belongsTo(Trace::class, 'trace_id');
    }

    public function getDurationColorAttribute(): string
    {
        $ms = $this->duration_ms ?? 0;
        if ($ms < 50)  return 'green';
        if ($ms < 200) return 'yellow';
        if ($ms < 1000) return 'orange';
        return 'red';
    }
}
