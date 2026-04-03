@extends('tracereplay::layout')
@section('title', 'Traces — TraceReplay')

@section('content')
<div class="space-y-6" x-data="{
    autoRefresh: false,
    timer: null,
    startAutoRefresh() {
        this.timer = setInterval(() => location.reload(), 10000);
    },
    stopAutoRefresh() {
        clearInterval(this.timer);
    }
}">
    <!-- Page Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h2 class="text-2xl font-semibold text-white">Traces</h2>
            <p class="text-sm text-gray-500 mt-1">{{ $traces->total() }} total</p>
        </div>

        <div class="flex items-center gap-3 flex-wrap">
            <!-- Search -->
            <form method="GET" action="{{ route('tracereplay.index') }}" class="flex gap-2">
                <input name="search" value="{{ request('search') }}"
                       placeholder="Search name, user, IP…"
                       class="px-3 py-2 text-sm rounded-lg bg-dark-700 border border-gray-700 text-gray-300 placeholder-gray-500 focus:outline-none focus:border-brand-500 w-56">

                <!-- Status filter -->
                <select name="status" onchange="this.form.submit()"
                        class="px-3 py-2 text-sm rounded-lg bg-dark-700 border border-gray-700 text-gray-300 focus:outline-none focus:border-brand-500">
                    <option value="" @selected(!request('status'))>All Statuses</option>
                    <option value="success" @selected(request('status')==='success')>✅ Success</option>
                    <option value="error"   @selected(request('status')==='error')>❌ Failed</option>
                    <option value="processing" @selected(request('status')==='processing')>⏳ Processing</option>
                </select>

                <button type="submit" class="px-4 py-2 text-sm rounded-lg bg-brand-500/20 border border-brand-500/40 text-brand-400 hover:bg-brand-500/30 transition">
                    Filter
                </button>
                @if(request()->hasAny(['search','status']))
                    <a href="{{ route('tracereplay.index') }}" class="px-4 py-2 text-sm rounded-lg bg-dark-700 border border-gray-700 text-gray-400 hover:text-white transition">
                        ✕ Clear
                    </a>
                @endif
            </form>

            <!-- Auto-refresh toggle -->
            <button @click="autoRefresh ? (stopAutoRefresh(), autoRefresh=false) : (startAutoRefresh(), autoRefresh=true)"
                    :class="autoRefresh ? 'bg-green-500/20 border-green-500/40 text-green-400' : 'bg-dark-700 border-gray-700 text-gray-400'"
                    class="px-3 py-2 text-sm rounded-lg border transition flex items-center gap-1.5">
                <span class="w-2 h-2 rounded-full" :class="autoRefresh ? 'bg-green-400 animate-pulse' : 'bg-gray-600'"></span>
                <span x-text="autoRefresh ? 'Live' : 'Auto'"></span>
            </button>
        </div>
    </div>

    <!-- Summary stats bar (live via fetch) -->
    <div id="stats-bar" class="grid grid-cols-2 sm:grid-cols-4 gap-4"
         x-init="fetch('{{ route('tracereplay.stats') }}').then(r=>r.json()).then(d=>{
             document.getElementById('stat-total').textContent  = d.total;
             document.getElementById('stat-failed').textContent = d.failed + ' (' + d.failure_rate + '%)';
             document.getElementById('stat-avg').textContent    = d.avg_duration + ' ms';
             document.getElementById('stat-today').textContent  = d.today;
         })">
        @foreach([['Total Traces','stat-total','blue'],['Failed','stat-failed','red'],['Avg Duration','stat-avg','yellow'],['Today','stat-today','green']] as [$label,$id,$c])
        <div class="glass-panel rounded-xl p-4 text-center">
            <div class="text-2xl font-bold text-{{ $c }}-400" id="{{ $id }}">—</div>
            <div class="text-xs text-gray-500 mt-1">{{ $label }}</div>
        </div>
        @endforeach
    </div>

    <div class="glass-panel rounded-xl overflow-hidden shadow-2xl">
        <table class="w-full text-left border-collapse">
            <thead>
                <tr class="bg-dark-800/50 border-b border-gray-800">
                    <th class="py-4 px-5 font-medium text-xs text-gray-400 uppercase tracking-wider">Status</th>
                    <th class="py-4 px-5 font-medium text-xs text-gray-400 uppercase tracking-wider">Trace</th>
                    <th class="py-4 px-5 font-medium text-xs text-gray-400 uppercase tracking-wider">User / IP</th>
                    <th class="py-4 px-5 font-medium text-xs text-gray-400 uppercase tracking-wider">Started</th>
                    <th class="py-4 px-5 font-medium text-xs text-gray-400 uppercase tracking-wider">Duration</th>
                    <th class="py-4 px-5 font-medium text-xs text-gray-400 uppercase tracking-wider text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-800">
                @forelse ($traces as $trace)
                    <tr class="hover:bg-white/[0.02] transition-colors">
                        <td class="py-3 px-5">
                            @php $s=$trace->status; @endphp
                            @if($s==='success')
                                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium bg-green-500/10 text-green-400 border border-green-500/20">
                                    <span class="w-1.5 h-1.5 rounded-full bg-green-400"></span>Success
                                </span>
                            @elseif($s==='error')
                                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium bg-red-500/10 text-red-400 border border-red-500/20">
                                    <span class="w-1.5 h-1.5 rounded-full bg-red-400"></span>Failed
                                </span>
                            @else
                                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium bg-yellow-500/10 text-yellow-400 border border-yellow-500/20">
                                    <span class="w-1.5 h-1.5 rounded-full bg-yellow-400 animate-pulse"></span>Processing
                                </span>
                            @endif
                        </td>
                        <td class="py-3 px-5">
                            <div class="font-medium text-gray-200">{{ $trace->name ?? 'Unnamed' }}</div>
                            <div class="text-xs text-gray-500 font-mono mt-0.5 flex gap-3">
                                <span>{{ substr($trace->id, 0, 8) }}</span>
                                <span>{{ $trace->steps_count }} steps</span>
                                @if($trace->tags)
                                    @foreach((array)$trace->tags as $tag)
                                        <span class="px-1.5 py-0.5 rounded bg-dark-700 text-gray-400">{{ $tag }}</span>
                                    @endforeach
                                @endif
                            </div>
                        </td>
                        <td class="py-3 px-5 text-sm text-gray-400">
                            @if($trace->user_id)
                                <div class="text-gray-300">User #{{ $trace->user_id }}</div>
                            @endif
                            <div class="text-xs text-gray-500">{{ $trace->ip_address ?? '—' }}</div>
                        </td>
                        <td class="py-3 px-5 text-sm text-gray-400">
                            {{ $trace->started_at?->diffForHumans() ?? '—' }}
                        </td>
                        <td class="py-3 px-5 text-sm">
                            @if($trace->duration_ms)
                                @php $ms = $trace->duration_ms; $c = $ms < 200 ? 'green' : ($ms < 1000 ? 'yellow' : 'red'); @endphp
                                <span class="text-{{ $c }}-400 font-mono">{{ number_format($ms, 0) }} ms</span>
                            @else
                                <span class="text-gray-500">—</span>
                            @endif
                        </td>
                        <td class="py-3 px-5 text-right flex items-center justify-end gap-2">
                            <a href="{{ route('tracereplay.show', $trace->id) }}"
                               class="inline-flex items-center gap-1 px-3 py-1.5 rounded text-xs bg-dark-700 text-gray-300 hover:text-white hover:bg-dark-600 transition">
                                View →
                            </a>
                            <a href="{{ route('tracereplay.export', $trace->id) }}"
                               class="inline-flex items-center gap-1 px-3 py-1.5 rounded text-xs bg-dark-700 text-gray-400 hover:text-white transition"
                               title="Export JSON">↓</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="py-16 text-center text-gray-500">
                            <div class="flex flex-col items-center">
                                <i data-feather="inbox" class="w-10 h-10 mb-3 opacity-30"></i>
                                <p class="font-medium">No traces recorded yet.</p>
                                <p class="text-xs mt-1">Instrument your code with <code>TraceReplay::step()</code> or enable the middleware.</p>
                            </div>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        @if ($traces->hasPages())
            <div class="px-6 py-4 border-t border-gray-800 bg-dark-800/30 flex justify-between items-center text-sm text-gray-400">
                <span>Showing {{ $traces->firstItem() }}–{{ $traces->lastItem() }} of {{ $traces->total() }}</span>
                {{ $traces->links() }}
            </div>
        @endif
    </div>
</div>
@endsection
