@extends('trace-replay::layout')
@section('title', 'Dashboard — TraceReplay')

@section('content')
<div class="space-y-6" x-data="{
    autoRefresh: localStorage.getItem('traceReplayAutoRefresh') === 'true',
    timer: null,
    lastRefresh: Date.now(),
    init() {
        if (this.autoRefresh) this.startAutoRefresh();
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible' && this.autoRefresh && Date.now() - this.lastRefresh > 10000) {
                location.reload();
            }
        });
    },
    startAutoRefresh() {
        this.lastRefresh = Date.now();
        this.timer = setInterval(() => { this.lastRefresh = Date.now(); location.reload(); }, 10000);
        localStorage.setItem('traceReplayAutoRefresh', 'true');
    },
    stopAutoRefresh() {
        clearInterval(this.timer);
        this.timer = null;
        localStorage.setItem('traceReplayAutoRefresh', 'false');
    }
}">
    <!-- Dashboard Header -->
    <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4">
        <div>
            <h2 class="text-2xl font-bold text-white flex items-center gap-3">
                <span class="w-10 h-10 rounded-xl bg-gradient-to-br from-brand-500 to-purple-600 flex items-center justify-center">
                    <i data-feather="activity" class="w-5 h-5 text-white"></i>
                </span>
                TraceReplay
            </h2>
            <p class="text-sm text-gray-500 mt-1">Real-time application tracing & debugging</p>
        </div>

        <div class="flex items-center gap-3">
            <!-- Feature highlights - collapsible -->
            <div x-data="{ showFeatures: false }" class="relative">
                <button @click="showFeatures = !showFeatures"
                        class="px-3 py-2 text-sm rounded-lg bg-gradient-to-r from-purple-500/20 to-indigo-500/20 border border-purple-500/30 text-purple-300 hover:border-purple-400/50 transition flex items-center gap-1.5">
                    <i data-feather="zap" class="w-4 h-4"></i>
                    <span>Features</span>
                    <i data-feather="chevron-down" class="w-3 h-3 transition-transform" :class="showFeatures ? 'rotate-180' : ''"></i>
                </button>

                <!-- Features dropdown -->
                <div x-show="showFeatures"
                     x-transition:enter="transition ease-out duration-200"
                     x-transition:enter-start="opacity-0 scale-95"
                     x-transition:enter-end="opacity-100 scale-100"
                     x-transition:leave="transition ease-in duration-150"
                     x-transition:leave-start="opacity-100 scale-100"
                     x-transition:leave-end="opacity-0 scale-95"
                     @click.away="showFeatures = false"
                     class="absolute right-0 top-full mt-2 w-80 glass-panel rounded-xl shadow-2xl border border-gray-700 p-4 z-50">
                    <h4 class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-3">Unique Features</h4>
                    <div class="grid grid-cols-2 gap-2 text-xs">
                        <div class="flex items-center gap-2 p-2 rounded-lg bg-dark-800/50 border border-gray-800">
                            <span class="text-green-400">✓</span>
                            <span class="text-gray-300">HTTP Replay</span>
                        </div>
                        <div class="flex items-center gap-2 p-2 rounded-lg bg-dark-800/50 border border-gray-800">
                            <span class="text-green-400">✓</span>
                            <span class="text-gray-300">JSON Diff</span>
                        </div>
                        <div class="flex items-center gap-2 p-2 rounded-lg bg-dark-800/50 border border-gray-800">
                            <span class="text-green-400">✓</span>
                            <span class="text-gray-300">AI Fix Prompts</span>
                        </div>
                        <div class="flex items-center gap-2 p-2 rounded-lg bg-dark-800/50 border border-gray-800">
                            <span class="text-green-400">✓</span>
                            <span class="text-gray-300">PII Masking</span>
                        </div>
                        <div class="flex items-center gap-2 p-2 rounded-lg bg-dark-800/50 border border-gray-800">
                            <span class="text-green-400">✓</span>
                            <span class="text-gray-300">Livewire Tracing</span>
                        </div>
                        <div class="flex items-center gap-2 p-2 rounded-lg bg-dark-800/50 border border-gray-800">
                            <span class="text-green-400">✓</span>
                            <span class="text-gray-300">Multi-Tenant</span>
                        </div>
                        <div class="flex items-center gap-2 p-2 rounded-lg bg-dark-800/50 border border-gray-800">
                            <span class="text-green-400">✓</span>
                            <span class="text-gray-300">W3C Traceparent</span>
                        </div>
                        <div class="flex items-center gap-2 p-2 rounded-lg bg-dark-800/50 border border-gray-800">
                            <span class="text-green-400">✓</span>
                            <span class="text-gray-300">Octane Ready</span>
                        </div>
                    </div>
                    <div class="mt-3 pt-3 border-t border-gray-800">
                        <a href="https://github.com/iazaran/trace-replay#-key-features" target="_blank"
                           class="text-xs text-brand-400 hover:text-brand-300 flex items-center gap-1">
                            View all features <span>→</span>
                        </a>
                    </div>
                </div>
            </div>

            <!-- Auto-refresh toggle -->
            <button @click="autoRefresh ? (stopAutoRefresh(), autoRefresh=false) : (startAutoRefresh(), autoRefresh=true)"
                    :class="autoRefresh ? 'bg-green-500/20 border-green-500/40 text-green-400' : 'bg-dark-700 border-gray-700 text-gray-400'"
                    class="px-3 py-2 text-sm rounded-lg border transition flex items-center gap-1.5">
                <span class="w-2 h-2 rounded-full" :class="autoRefresh ? 'bg-green-400 animate-pulse' : 'bg-gray-600'"></span>
                <span x-text="autoRefresh ? 'Live' : 'Auto'"></span>
            </button>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="grid grid-cols-2 lg:grid-cols-5 gap-4">
        <div class="glass-panel rounded-xl p-4">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-lg bg-blue-500/20 flex items-center justify-center">
                    <i data-feather="layers" class="w-5 h-5 text-blue-400"></i>
                </div>
                <div>
                    <div class="text-2xl font-bold text-white">{{ number_format($stats['total']) }}</div>
                    <div class="text-xs text-gray-500">Total Traces</div>
                </div>
            </div>
        </div>
        <div class="glass-panel rounded-xl p-4">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-lg bg-green-500/20 flex items-center justify-center">
                    <i data-feather="check-circle" class="w-5 h-5 text-green-400"></i>
                </div>
                <div>
                    <div class="text-2xl font-bold text-green-400">{{ number_format($stats['success']) }}</div>
                    <div class="text-xs text-gray-500">Successful</div>
                </div>
            </div>
        </div>
        <div class="glass-panel rounded-xl p-4">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-lg bg-red-500/20 flex items-center justify-center">
                    <i data-feather="alert-circle" class="w-5 h-5 text-red-400"></i>
                </div>
                <div>
                    <div class="text-2xl font-bold text-red-400">{{ number_format($stats['errors']) }}</div>
                    <div class="text-xs text-gray-500">Errors ({{ $stats['error_rate'] }}%)</div>
                </div>
            </div>
        </div>
        <div class="glass-panel rounded-xl p-4">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-lg bg-yellow-500/20 flex items-center justify-center">
                    <i data-feather="clock" class="w-5 h-5 text-yellow-400"></i>
                </div>
                <div>
                    <div class="text-2xl font-bold text-yellow-400">{{ number_format($stats['avg_duration'], 0) }}<span class="text-sm font-normal">ms</span></div>
                    <div class="text-xs text-gray-500">Avg Duration</div>
                </div>
            </div>
        </div>
        <div class="glass-panel rounded-xl p-4">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-lg bg-purple-500/20 flex items-center justify-center">
                    <i data-feather="zap" class="w-5 h-5 text-purple-400"></i>
                </div>
                <div>
                    <div class="text-2xl font-bold text-purple-400">{{ number_format($stats['last_hour']) }}</div>
                    <div class="text-xs text-gray-500">Last Hour</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Charts Row -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
        <!-- Trend Chart -->
        <div class="lg:col-span-2 glass-panel rounded-xl p-5">
            <h3 class="text-sm font-medium text-gray-400 mb-4">Last 7 Days Activity</h3>
            <div class="h-40" x-data="{
                trend: {{ json_encode($stats['trend']) }},
                formatDate(dateStr) {
                    const date = new Date(dateStr);
                    const days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
                    const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
                    return days[date.getDay()] + ' ' + months[date.getMonth()] + ' ' + date.getDate();
                },
                getMaxTotal() {
                    return Math.max(...this.trend.map(d => d.total), 1);
                }
            }">
                <div class="flex items-end justify-between h-full gap-2">
                    <template x-for="(day, index) in trend" :key="index">
                        <div class="flex-1 flex flex-col items-center gap-1 group cursor-pointer"
                             :title="day.total + ' traces (' + day.errors + ' errors)'">
                            <div class="w-full flex flex-col-reverse relative">
                                <div class="w-full bg-brand-500/60 rounded-t transition-all duration-300 group-hover:bg-brand-500/80"
                                     :style="'height: ' + Math.max(4, ((day.total - day.errors) / getMaxTotal()) * 100) + 'px'"></div>
                                <div class="w-full bg-red-500/80 rounded-t transition-all duration-300 group-hover:bg-red-500"
                                     :style="'height: ' + Math.max(0, (day.errors / getMaxTotal()) * 100) + 'px'"></div>
                                <!-- Tooltip on hover -->
                                <div class="absolute -top-8 left-1/2 -translate-x-1/2 hidden group-hover:block bg-dark-800 text-white text-[10px] px-2 py-1 rounded shadow-lg whitespace-nowrap z-10">
                                    <span x-text="day.total"></span> traces
                                </div>
                            </div>
                            <span class="text-[10px] text-gray-500 group-hover:text-gray-300 transition-colors" x-text="formatDate(day.date)"></span>
                        </div>
                    </template>
                    <template x-if="trend.length === 0">
                        <div class="flex-1 flex items-center justify-center text-gray-600 text-sm">No data yet</div>
                    </template>
                </div>
            </div>
            <div class="flex items-center justify-center gap-6 mt-4 text-xs">
                <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded bg-brand-500/60"></span> Success</span>
                <span class="flex items-center gap-1.5"><span class="w-3 h-3 rounded bg-red-500/80"></span> Errors</span>
            </div>
        </div>

        <!-- Operations Breakdown -->
        <div class="glass-panel rounded-xl p-5">
            <h3 class="text-sm font-medium text-gray-400 mb-4">Operations (7 days)</h3>
            @php
                $ops = $stats['operations'] ?? [];
                $opsMeta = [
                    'db_queries' => ['label' => 'DB Queries', 'icon' => 'database', 'color' => 'blue'],
                    'cache_calls' => ['label' => 'Cache Calls', 'icon' => 'hard-drive', 'color' => 'green'],
                    'http_calls' => ['label' => 'HTTP Calls', 'icon' => 'globe', 'color' => 'purple'],
                    'mail_calls' => ['label' => 'Mail Sent', 'icon' => 'mail', 'color' => 'orange'],
                ];
                $maxOps = max(1, max($ops['db_queries'] ?? 0, $ops['cache_calls'] ?? 0, $ops['http_calls'] ?? 0, $ops['mail_calls'] ?? 0));
            @endphp
            <div class="space-y-3">
                @foreach($opsMeta as $key => $meta)
                    @php
                        $count = $ops[$key] ?? 0;
                        $pct = $maxOps > 0 ? round(($count / $maxOps) * 100) : 0;
                    @endphp
                    <div class="group">
                        <div class="flex items-center justify-between mb-1">
                            <span class="flex items-center gap-2 text-sm text-gray-300">
                                <i data-feather="{{ $meta['icon'] }}" class="w-4 h-4 text-{{ $meta['color'] }}-400"></i>
                                {{ $meta['label'] }}
                            </span>
                            <span class="text-xs font-medium text-{{ $meta['color'] }}-400">{{ number_format($count) }}</span>
                        </div>
                        <div class="h-2 bg-dark-700 rounded-full overflow-hidden">
                            <div class="h-full bg-{{ $meta['color'] }}-500/60 rounded-full transition-all"
                                 style="width: {{ $pct }}%"></div>
                        </div>
                    </div>
                @endforeach
                @if(array_sum($ops) === 0)
                    <div class="text-center text-gray-600 py-4 text-sm">No operations recorded yet</div>
                @endif
            </div>
        </div>
    </div>

    <!-- Filters Bar -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pt-2">
        <h3 class="text-lg font-semibold text-white">Recent Traces</h3>

        <form method="GET" action="{{ route('trace-replay.index') }}" class="flex flex-wrap items-center gap-2">
            <input name="search" value="{{ request('search') }}" placeholder="Search..."
                   class="px-3 py-2 text-sm rounded-lg bg-dark-700 border border-gray-700 text-gray-300 placeholder-gray-500 focus:outline-none focus:border-brand-500 w-40">

            <select name="date_range" onchange="this.form.submit()"
                    class="px-3 py-2 text-sm rounded-lg bg-dark-700 border border-gray-700 text-gray-300 focus:outline-none focus:border-brand-500">
                <option value="" @selected(!request('date_range'))>All Time</option>
                <option value="today" @selected(request('date_range')==='today')>📅 Today</option>
                <option value="yesterday" @selected(request('date_range')==='yesterday')>📅 Yesterday</option>
                <option value="7days" @selected(request('date_range')==='7days')>📅 Last 7 Days</option>
                <option value="30days" @selected(request('date_range')==='30days')>📅 Last 30 Days</option>
                <option value="hour" @selected(request('date_range')==='hour')>⏰ Last Hour</option>
            </select>

            <select name="type" onchange="this.form.submit()"
                    class="px-3 py-2 text-sm rounded-lg bg-dark-700 border border-gray-700 text-gray-300 focus:outline-none focus:border-brand-500">
                <option value="" @selected(!request('type'))>All Types</option>
                <option value="http" @selected(request('type')==='http')>🌐 HTTP</option>
                <option value="job" @selected(request('type')==='job')>⚙️ Job</option>
                <option value="command" @selected(request('type')==='command')>💻 Command</option>
                <option value="schedule" @selected(request('type')==='schedule')>⏰ Schedule</option>
            </select>

            <select name="status" onchange="this.form.submit()"
                    class="px-3 py-2 text-sm rounded-lg bg-dark-700 border border-gray-700 text-gray-300 focus:outline-none focus:border-brand-500">
                <option value="" @selected(!request('status'))>All Status</option>
                <option value="success" @selected(request('status')==='success')>✅ Success</option>
                <option value="error" @selected(request('status')==='error')>❌ Error</option>
                <option value="processing" @selected(request('status')==='processing')>⏳ Processing</option>
            </select>

            @if(request()->hasAny(['search','status','type','date_range']))
                <a href="{{ route('trace-replay.index') }}" class="px-3 py-2 text-sm rounded-lg bg-dark-700 border border-gray-700 text-gray-400 hover:text-white transition">
                    ✕ Clear
                </a>
            @endif
        </form>
    </div>

    <!-- Traces Table -->
    <div class="glass-panel rounded-xl overflow-hidden shadow-2xl">
        <table class="w-full text-left border-collapse">
            <thead>
                <tr class="bg-dark-800/50 border-b border-gray-800">
                    <th class="py-3 px-4 font-medium text-xs text-gray-400 uppercase tracking-wider w-16">Type</th>
                    <th class="py-3 px-4 font-medium text-xs text-gray-400 uppercase tracking-wider w-20">Status</th>
                    <th class="py-3 px-4 font-medium text-xs text-gray-400 uppercase tracking-wider">Name</th>
                    <th class="py-3 px-4 font-medium text-xs text-gray-400 uppercase tracking-wider w-32">Started</th>
                    <th class="py-3 px-4 font-medium text-xs text-gray-400 uppercase tracking-wider w-24">Duration</th>
                    <th class="py-3 px-4 font-medium text-xs text-gray-400 uppercase tracking-wider text-center w-20">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-800">
                @forelse ($traces as $trace)
                    @php
                        $type = $trace->type ?? 'http';
                        $typeIcons = ['http' => 'globe', 'job' => 'cpu', 'command' => 'terminal', 'schedule' => 'clock'];
                        $typeColors = ['http' => 'brand', 'job' => 'purple', 'command' => 'orange', 'schedule' => 'cyan'];
                    @endphp
                    <tr class="hover:bg-white/[0.02] transition-colors cursor-pointer group" onclick="window.location='{{ route('trace-replay.show', $trace->id) }}'">
                        <!-- Type -->
                        <td class="py-3 px-4">
                            <span class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-{{ $typeColors[$type] ?? 'gray' }}-500/20" title="{{ ucfirst($type) }}">
                                <i data-feather="{{ $typeIcons[$type] ?? 'box' }}" class="w-4 h-4 text-{{ $typeColors[$type] ?? 'gray' }}-400"></i>
                            </span>
                        </td>

                        <!-- Status -->
                        <td class="py-3 px-4">
                            @php $s=$trace->status; @endphp
                            @if($s==='success')
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-medium bg-green-500/10 text-green-400 border border-green-500/20">
                                    <span class="w-1.5 h-1.5 rounded-full bg-green-400"></span>OK
                                </span>
                            @elseif($s==='error')
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-medium bg-red-500/10 text-red-400 border border-red-500/20">
                                    <span class="w-1.5 h-1.5 rounded-full bg-red-400"></span>Error
                                </span>
                            @else
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-medium bg-yellow-500/10 text-yellow-400 border border-yellow-500/20">
                                    <span class="w-1.5 h-1.5 rounded-full bg-yellow-400 animate-pulse"></span>...
                                </span>
                            @endif
                        </td>

                        <!-- Name -->
                        <td class="py-3 px-4">
                            @php
                                $displayName = $trace->name ?? 'Trace';
                                $method = null;
                                if ($type === 'http' && str_starts_with($displayName, 'HTTP ')) {
                                    $parts = explode(' ', $displayName, 3);
                                    $method = $parts[1] ?? null;
                                    $displayName = $parts[2] ?? $displayName;
                                }
                            @endphp
                            <div class="flex items-center gap-2 min-w-0">
                                @if($method)
                                    <span class="shrink-0 px-1.5 py-0.5 text-[10px] font-bold uppercase rounded bg-dark-800 text-brand-300 border border-gray-700">{{ $method }}</span>
                                @endif
                                <span class="font-medium text-gray-100 truncate text-sm" title="{{ $trace->name }}">{{ \Illuminate\Support\Str::limit($displayName, 40) }}</span>
                                @if($trace->http_status)
                                    <span class="shrink-0 text-[11px] font-mono {{ $trace->http_status >= 400 ? 'text-red-400' : 'text-gray-500' }}">{{ $trace->http_status }}</span>
                                @endif
                            </div>
                            <div class="text-[11px] text-gray-500 font-mono mt-0.5 flex items-center gap-2">
                                <span>{{ substr($trace->id, 0, 8) }}</span>
                                <span class="text-gray-600">·</span>
                                <span>{{ $trace->steps_count ?? 0 }} steps</span>
                                @if($trace->ip_address)
                                    <span class="text-gray-600">·</span>
                                    <span>{{ $trace->ip_address }}</span>
                                @endif
                            </div>
                        </td>

                        <!-- Started -->
                        <td class="py-3 px-4 text-xs text-gray-400">
                            <div>{{ $trace->started_at?->diffForHumans() ?? '—' }}</div>
                            <div class="text-[10px] text-gray-600">{{ $trace->started_at?->format('H:i:s') }}</div>
                        </td>

                        <!-- Duration -->
                        <td class="py-3 px-4 text-sm">
                            @if($trace->duration_ms)
                                @php
                                    $ms = $trace->duration_ms;
                                    $c = $ms < 200 ? 'green' : ($ms < 1000 ? 'yellow' : 'red');
                                @endphp
                                <div class="flex items-center gap-2">
                                    <span class="w-2 h-2 rounded-full @if($c==='green') bg-green-500 @elseif($c==='yellow') bg-yellow-400 @else bg-red-500 @endif"></span>
                                    <span class="text-{{ $c }}-400 font-mono text-xs">{{ number_format($ms, 0) }}ms</span>
                                </div>
                            @else
                                <span class="text-gray-500">—</span>
                            @endif
                        </td>

                        <!-- Actions -->
                        <td class="py-3 px-4 text-center" onclick="event.stopPropagation()">
                            <div class="flex items-center justify-center gap-1">
                                <a href="{{ route('trace-replay.show', $trace->id) }}"
                                   class="inline-flex items-center justify-center w-7 h-7 rounded text-gray-400 hover:text-white hover:bg-dark-600 transition"
                                   title="View details">
                                    <i data-feather="eye" class="w-3.5 h-3.5"></i>
                                </a>
                                <a href="{{ route('trace-replay.export', $trace->id) }}"
                                   class="inline-flex items-center justify-center w-7 h-7 rounded text-gray-400 hover:text-white hover:bg-dark-600 transition"
                                   title="Download JSON">
                                    <i data-feather="download" class="w-3.5 h-3.5"></i>
                                </a>
                            </div>
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
