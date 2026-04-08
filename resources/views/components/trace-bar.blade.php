@if(config('trace-replay.enabled') && $trace)
<div id="trace-replay-bar"
     style="position:fixed;bottom:0;left:0;right:0;z-index:9999;font-family:monospace;font-size:12px;background:#0f1117;color:#c9d1d9;border-top:1px solid #21262d;display:flex;align-items:center;gap:16px;padding:6px 16px;">
    
    <!-- Icon + Brand -->
    <span style="color:#3b82f6;font-weight:700;">⚡ TraceReplay</span>
    
    <!-- Trace Name -->
    <span style="color:#8b949e;">{{ $trace->name ?? 'Trace' }}</span>
    
    <!-- Status dot -->
    @php $color = $trace->status === 'error' ? '#f87171' : ($trace->status === 'processing' ? '#fbbf24' : '#4ade80'); @endphp
    <span style="width:8px;height:8px;border-radius:50%;background:{{ $color }};display:inline-block;"></span>

    <!-- Step count -->
    <span style="color:#8b949e;">
        <strong style="color:#c9d1d9;">{{ $trace->steps->count() }}</strong> steps
    </span>

    <!-- Duration -->
    @if($trace->duration_ms)
    <span style="color:#8b949e;">
        <strong style="color:#c9d1d9;">{{ number_format($trace->duration_ms, 1) }}</strong> ms
    </span>
    @endif

    <!-- Trace ID -->
    <span style="color:#4b5563;">{{ substr($trace->id, 0, 8) }}</span>

    <!-- Dashboard link -->
    <a href="{{ route('trace-replay.show', $trace->id) }}"
       style="margin-left:auto;color:#3b82f6;text-decoration:none;" target="_blank">
        View in Dashboard →
    </a>

    <!-- Close button -->
    <button onclick="document.getElementById('trace-replay-bar').remove()"
            style="background:none;border:none;color:#6b7280;cursor:pointer;font-size:16px;line-height:1;">×</button>
</div>
@endif

