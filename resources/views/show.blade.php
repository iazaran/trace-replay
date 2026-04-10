@extends('trace-replay::layout')
@section('title', ($trace->name ?? 'Trace') . ' — TraceReplay')

@section('content')
<div class="flex flex-col lg:flex-row gap-6 h-[calc(100vh-8rem)]" x-data="traceViewer">
    
    <!-- Left Column: Graph / Timeline -->
    <div class="lg:w-1/3 flex flex-col gap-4">
        <!-- Trace Header Summary -->
        <div class="glass-panel p-5 rounded-xl shadow-lg border-l-4 {{ $trace->status === 'success' ? 'border-green-500' : 'border-red-500' }}">
            <div class="flex justify-between items-start gap-3 mb-2">
                <div class="flex-1 min-w-0">
                    <h2 class="text-xl font-bold text-white truncate" title="{{ $trace->name }}">{{ $trace->name }}</h2>
                </div>
                <span class="text-sm font-mono text-gray-500 flex-shrink-0">{{ substr($trace->id, 0, 8) }}</span>
            </div>
            
            <div class="flex items-center justify-between text-sm mt-4">
                <div class="flex items-center gap-2">
                    <i data-feather="clock" class="w-4 h-4 text-gray-400"></i>
                    <span class="text-gray-300">{{ number_format($trace->duration_ms ?? 0, 2) }} ms</span>
                </div>
                <div class="flex items-center gap-2">
                    <i data-feather="layers" class="w-4 h-4 text-gray-400"></i>
                    <span class="text-gray-300">{{ $trace->steps->count() }} Steps</span>
                </div>
            </div>

            <!-- Progress Bar -->
            <div class="mt-5 relative w-full h-2 bg-dark-700 rounded-full overflow-hidden">
                <div class="absolute top-0 left-0 h-full transition-all duration-1000 ease-out {{ $trace->status === 'success' ? 'bg-green-500' : 'bg-red-500' }}" style="width: {{ $trace->completion_percentage }}%"></div>
            </div>

            @if($trace->tags)
                <div class="mt-4 flex flex-wrap gap-2">
                    @foreach((array)$trace->tags as $tag)
                        <span class="px-2 py-0.5 rounded-full text-[11px] bg-dark-800 border border-gray-800 text-gray-400">{{ $tag }}</span>
                    @endforeach
                </div>
            @endif

            <div class="mt-5 grid grid-cols-1 sm:grid-cols-2 gap-4 text-xs text-gray-400">
                <div>
                    <span class="uppercase text-[10px] tracking-wider text-gray-500">HTTP Status</span>
                    <div class="text-sm text-gray-200 mt-1">{{ $trace->http_status ?? '—' }}</div>
                </div>
                <div>
                    <span class="uppercase text-[10px] tracking-wider text-gray-500">Started At</span>
                    <div class="text-sm text-gray-200 mt-1">{{ $trace->started_at?->format('M j, Y · H:i') ?? '—' }}</div>
                </div>
                <div>
                    <span class="uppercase text-[10px] tracking-wider text-gray-500">IP Address</span>
                    <div class="text-sm text-gray-200 mt-1">{{ $trace->ip_address ?? '—' }}</div>
                </div>
                <div>
                    <span class="uppercase text-[10px] tracking-wider text-gray-500">User Agent</span>
                    <div class="text-sm text-gray-200 mt-1">{{ $trace->user_agent ? \Illuminate\Support\Str::limit($trace->user_agent, 60) : '—' }}</div>
                </div>
            </div>

            {{-- Error Details Panel --}}
            @if($trace->status === 'error' && $trace->error_reason)
                <div class="mt-5 bg-red-500/10 border border-red-500/30 rounded-lg overflow-hidden" x-data="{ showTrace: false }">
                    <div class="px-4 py-3 bg-red-500/20 border-b border-red-500/30 flex items-center gap-2">
                        <i data-feather="alert-triangle" class="w-4 h-4 text-red-400"></i>
                        <span class="font-bold text-red-300">{{ $trace->error_reason['class'] ?? 'Exception' }}</span>
                        @if(isset($trace->error_reason['code']) && $trace->error_reason['code'])
                            <span class="px-1.5 py-0.5 rounded text-[10px] bg-red-500/30 text-red-300">Code: {{ $trace->error_reason['code'] }}</span>
                        @endif
                    </div>
                    <div class="p-4 space-y-3">
                        <div class="text-red-200 text-sm font-medium">{{ $trace->error_reason['message'] ?? 'Unknown error' }}</div>
                        @if(isset($trace->error_reason['file']))
                            <div class="text-xs font-mono text-red-400/70 bg-dark-900/50 px-3 py-2 rounded">
                                <span class="text-gray-500">File:</span> {{ $trace->error_reason['file'] }}<br>
                                <span class="text-gray-500">Line:</span> {{ $trace->error_reason['line'] ?? '?' }}
                            </div>
                        @endif
                        @if(isset($trace->error_reason['trace']) && is_array($trace->error_reason['trace']))
                            <button @click="showTrace = !showTrace" class="text-xs text-red-400 hover:text-red-300 flex items-center gap-1 transition">
                                <i data-feather="chevron-down" class="w-3 h-3 transition-transform" :class="showTrace ? 'rotate-180' : ''"></i>
                                <span x-text="showTrace ? 'Hide Stack Trace' : 'Show Stack Trace'"></span>
                            </button>
                            <div x-show="showTrace" x-collapse class="mt-2">
                                <pre class="text-[11px] font-mono text-red-300/70 bg-dark-900/70 p-3 rounded overflow-x-auto max-h-64 overflow-y-auto">@foreach($trace->error_reason['trace'] as $line){{ $line }}
@endforeach</pre>
                            </div>
                        @endif
                    </div>
                </div>
            @endif
        </div>

        <!-- Timeline -->
        <div class="glass-panel rounded-xl shadow-lg flex-grow overflow-y-auto overflow-x-hidden p-5 scrollbar-thin">
            <h3 class="text-sm font-semibold text-gray-400 uppercase tracking-wider mb-4 border-b border-gray-800 pb-2">Execution Flow</h3>

            <div class="relative mt-4 space-y-2">
                @foreach ($trace->steps as $index => $step)
                <div class="relative flex items-center gap-3 group cursor-pointer p-2 -mx-2 rounded-lg transition"
                    :class="selectedStep && selectedStep.id === '{{ $step->id }}' ? 'bg-brand-500/10 ring-1 ring-brand-500/30' : 'hover:bg-white/[0.02]'"
                    @click="selectStep(allSteps[{{ $index }}])">

                    <!-- Vertical line -->
                    @if(!$loop->last)
                    <div class="absolute left-[21px] top-10 w-0.5 h-[calc(100%-12px)] bg-gray-700/50"></div>
                    @endif

                    <!-- Marker -->
                    <div class="relative flex items-center justify-center w-6 h-6 rounded-full border-2 shrink-0
                        {{ $step->status === 'success' ? 'bg-dark-900 border-green-500' : ($step->status === 'error' ? 'bg-dark-900 border-red-500' : 'bg-dark-900 border-yellow-500') }}
                        group-hover:scale-110 transition-transform z-10">
                        <span class="text-[10px] font-bold text-gray-400">{{ $step->step_order }}</span>
                    </div>

                    <!-- Label -->
                    <div class="flex-1 flex items-center justify-between gap-2 min-w-0">
                        <span class="font-medium text-gray-200 text-sm truncate">{{ $step->label }}</span>
                        <span class="text-xs text-gray-500 whitespace-nowrap shrink-0">{{ $step->duration_ms }}ms</span>
                    </div>
                </div>
                @endforeach
            </div>
        </div>
    </div>

    <!-- Right Column: Step Inspector -->
    <div class="lg:w-2/3 flex flex-col gap-4">

        <!-- Inspector Header Actions -->
        <div class="glass-panel p-4 rounded-xl shadow-lg flex justify-between items-center">
            <div class="text-sm font-medium text-gray-300">
                <span x-show="!selectedStep">Select a step from the timeline to inspect</span>
                <span x-show="selectedStep" x-text="selectedStep ? 'Inspecting Step: ' + selectedStep.label : ''"></span>
            </div>
            <div class="flex gap-2">
                @if($trace->status === 'error' || ($trace->http_status && $trace->http_status >= 400))
                    <button @click="generateFixPrompt()"
                            class="px-4 py-1.5 text-sm font-medium rounded-lg bg-gradient-to-r from-purple-600 to-indigo-600 text-white shadow-lg hover:from-purple-500 hover:to-indigo-500 transition-all flex items-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                        AI Fix Prompt
                    </button>
                @endif
                <button @click="replayRequest()"
                        class="px-4 py-1.5 text-sm font-medium rounded-lg bg-white/10 text-white border border-white/10 hover:bg-white/20 transition-all flex items-center gap-2">
                    <i data-feather="play" class="w-4 h-4"></i> Replay
                </button>
            </div>
        </div>

        <!-- Waterfall Chart -->
        <div x-data="{ open: true }" class="glass-panel rounded-xl shadow-lg">
            <button @click="open = !open"
                    class="w-full flex justify-between items-center px-5 py-3 text-sm font-medium text-gray-300 hover:text-white transition">
                <span class="flex items-center gap-2"><i data-feather="bar-chart-2" class="w-4 h-4"></i> Waterfall Timeline</span>
                <span x-text="open ? '▲' : '▼'" class="text-xs text-gray-500"></span>
            </button>
            <div x-show="open" class="px-5 pb-4 overflow-x-auto">
                @php $totalMs = max($trace->duration_ms ?? 1, 1); @endphp
                <div class="min-w-max space-y-1 mt-2">
                    @foreach($trace->steps as $step)
                    @php
                        $pct    = min(max(($step->duration_ms / $totalMs) * 100, 0.5), 100);
                        $offset = 0;
                        $color  = $step->status === 'error' ? '#f85149'
                               : ($step->duration_ms < 200 ? '#3fb950'
                               : ($step->duration_ms < 1000 ? '#d29922' : '#f0883e'));
                    @endphp
                    <div class="flex items-center gap-3 text-xs">
                        <span class="w-6 text-gray-500 text-right">{{ $step->step_order }}</span>
                        <span class="w-40 text-gray-300 truncate">{{ $step->label }}</span>
                        <div class="flex-1 relative h-5 bg-dark-700 rounded overflow-hidden" style="min-width:200px">
                            <div class="absolute top-1 bottom-1 rounded"
                                 style="left:{{ $offset }}%;width:{{ $pct }}%;background:{{ $color }};opacity:.8;min-width:4px"></div>
                        </div>
                        <span class="w-20 text-right font-mono" style="color:{{ $color }}">{{ number_format($step->duration_ms, 0) }} ms</span>
                    </div>
                    @endforeach
                </div>
                <div class="flex justify-between text-xs text-gray-500 mt-2 pl-52">
                    <span>0 ms</span><span>{{ number_format($totalMs/2, 0) }} ms</span><span>{{ number_format($totalMs, 0) }} ms</span>
                </div>
            </div>
        </div>

        <!-- Inspector Body -->
        <div class="glass-panel rounded-xl shadow-lg flex-grow overflow-hidden flex flex-col" x-show="selectedStep" x-cloak>

            <!-- Tabs -->
            <div class="flex border-b border-gray-800 bg-dark-800/50 px-4">
                <button @click="activeTab = 'request'" :class="{'border-b-2 border-brand-500 text-brand-400': activeTab === 'request', 'text-gray-400 hover:text-gray-300': activeTab !== 'request'}" class="px-4 py-3 text-sm font-medium transition-colors">Request Context</button>
                <button @click="activeTab = 'response'" :class="{'border-b-2 border-brand-500 text-brand-400': activeTab === 'response', 'text-gray-400 hover:text-gray-300': activeTab !== 'response'}" class="px-4 py-3 text-sm font-medium transition-colors">Response</button>
                <button @click="activeTab = 'error'" x-show="selectedStep?.status === 'error'" :class="{'border-b-2 border-red-500 text-red-500': activeTab === 'error', 'text-red-400/70 hover:text-red-400': activeTab !== 'error'}" class="px-4 py-3 text-sm font-medium transition-colors">Error Details</button>
            </div>

            <!-- Quick Jump Navigation - Shows available sections -->
            <div class="flex items-center gap-2 px-4 py-2 bg-dark-800/30 border-b border-gray-800 overflow-x-auto" x-show="activeTab === 'request'">
                <span class="text-xs text-gray-500 shrink-0">Jump to:</span>
                <button @click="document.getElementById('section-payload')?.scrollIntoView({behavior: 'smooth', block: 'start'})"
                        class="px-2 py-1 text-[11px] rounded bg-dark-700 text-gray-400 hover:text-white hover:bg-dark-600 transition shrink-0">
                    Payload
                </button>
                <button @click="document.getElementById('section-queries')?.scrollIntoView({behavior: 'smooth', block: 'start'})"
                        x-show="selectedStep?.db_queries?.length > 0"
                        class="px-2 py-1 text-[11px] rounded bg-blue-500/20 text-blue-400 hover:bg-blue-500/30 transition shrink-0 flex items-center gap-1">
                    <span>DB Queries</span>
                    <span class="font-mono" x-text="'(' + (selectedStep?.db_query_count || selectedStep?.db_queries?.length || 0) + ')'"></span>
                </button>
                <button @click="document.getElementById('section-cache')?.scrollIntoView({behavior: 'smooth', block: 'start'})"
                        x-show="selectedStep?.cache_calls?.length > 0"
                        class="px-2 py-1 text-[11px] rounded bg-green-500/20 text-green-400 hover:bg-green-500/30 transition shrink-0 flex items-center gap-1">
                    <span>Cache</span>
                    <span class="font-mono" x-text="'(' + (selectedStep?.cache_calls?.length || 0) + ')'"></span>
                </button>
                <button @click="document.getElementById('section-http')?.scrollIntoView({behavior: 'smooth', block: 'start'})"
                        x-show="selectedStep?.http_calls?.length > 0"
                        class="px-2 py-1 text-[11px] rounded bg-purple-500/20 text-purple-400 hover:bg-purple-500/30 transition shrink-0 flex items-center gap-1">
                    <span>HTTP</span>
                    <span class="font-mono" x-text="'(' + (selectedStep?.http_calls?.length || 0) + ')'"></span>
                </button>
                <button @click="document.getElementById('section-mail')?.scrollIntoView({behavior: 'smooth', block: 'start'})"
                        x-show="selectedStep?.mail_calls?.length > 0"
                        class="px-2 py-1 text-[11px] rounded bg-orange-500/20 text-orange-400 hover:bg-orange-500/30 transition shrink-0 flex items-center gap-1">
                    <span>Mail</span>
                    <span class="font-mono" x-text="'(' + (selectedStep?.mail_calls?.length || 0) + ')'"></span>
                </button>
                <button @click="document.getElementById('section-logs')?.scrollIntoView({behavior: 'smooth', block: 'start'})"
                        x-show="selectedStep?.log_calls?.length > 0"
                        class="px-2 py-1 text-[11px] rounded bg-gray-500/20 text-gray-400 hover:bg-gray-500/30 transition shrink-0 flex items-center gap-1">
                    <span>Logs</span>
                    <span class="font-mono" x-text="'(' + (selectedStep?.log_calls?.length || 0) + ')'"></span>
                </button>
            </div>

            <!-- Content Area -->
            <div class="p-4 overflow-y-auto flex-grow bg-dark-900/50 space-y-4" id="inspector-content">

                <!-- Step meta row -->
                <div class="flex flex-wrap gap-3 text-xs text-gray-400" x-show="selectedStep">
                    <span class="px-2 py-1 rounded bg-dark-700" x-text="selectedStep ? 'Step #' + selectedStep.step_order : ''"></span>
                    <span class="px-2 py-1 rounded bg-dark-700" x-text="selectedStep ? selectedStep.duration_ms + ' ms' : ''"></span>
                    <span class="px-2 py-1 rounded bg-dark-700" x-show="selectedStep && selectedStep.db_query_count"
                          x-text="selectedStep ? selectedStep.db_query_count + ' queries (' + selectedStep.db_query_time_ms + ' ms)' : ''"></span>
                    <span class="px-2 py-1 rounded bg-dark-700" x-show="selectedStep && selectedStep.memory_usage"
                          x-text="selectedStep ? 'Mem: ' + Math.round((selectedStep.memory_usage||0)/1024) + ' KB' : ''"></span>
                </div>

                <!-- Request Tab -->
                <div x-show="activeTab === 'request'" class="space-y-4">
                    <!-- Request Payload -->
                    <div id="section-payload">
                        <h4 class="text-xs uppercase text-gray-500 font-semibold mb-2 tracking-wider">Request Payload</h4>
                        <pre class="bg-dark-900 p-4 rounded-lg font-mono text-sm overflow-x-auto border border-gray-800"><code class="text-green-300" x-text="formatJSON(selectedStep?.request_payload)"></code></pre>
                    </div>

                    <!-- DB Queries -->
                    <div id="section-queries" x-show="selectedStep?.db_queries && selectedStep?.db_queries.length > 0">
                        <h4 class="text-xs uppercase text-gray-500 font-semibold mb-2 tracking-wider flex items-center gap-2">
                            <i data-feather="database" class="w-3 h-3"></i>
                            Database Queries
                            <span class="text-brand-400 font-mono" x-text="'(' + (selectedStep?.db_query_count || selectedStep?.db_queries?.length || 0) + ')'"></span>
                        </h4>
                        <div class="space-y-2">
                            <template x-for="(query, idx) in selectedStep?.db_queries || []" :key="idx">
                                <div class="bg-dark-900 p-3 rounded-lg border border-gray-800">
                                    <div class="flex items-center justify-between mb-2">
                                        <span class="text-xs text-gray-500" x-text="'Query #' + (idx + 1)"></span>
                                        <span class="text-xs font-mono" :class="(query.time || query.duration_ms) > 100 ? 'text-red-400' : 'text-green-400'" x-text="(query.time || query.duration_ms || 0).toFixed(2) + ' ms'"></span>
                                    </div>
                                    <pre class="font-mono text-xs text-blue-300 whitespace-pre-wrap break-all" x-text="query.sql || query.query || query"></pre>
                                    <div x-show="query.bindings && query.bindings.length > 0" class="mt-2 pt-2 border-t border-gray-800">
                                        <span class="text-[10px] uppercase text-gray-600">Bindings:</span>
                                        <span class="text-xs text-yellow-300 font-mono" x-text="JSON.stringify(query.bindings)"></span>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>

                    <!-- Cache Calls -->
                    <div id="section-cache" x-show="selectedStep?.cache_calls && selectedStep?.cache_calls.length > 0">
                        <h4 class="text-xs uppercase text-gray-500 font-semibold mb-2 tracking-wider flex items-center gap-2">
                            <i data-feather="hard-drive" class="w-3 h-3"></i>
                            Cache Operations
                            <span class="text-green-400 font-mono" x-text="'(' + (selectedStep?.cache_hit_count || 0) + ' hits)'"></span>
                            <span class="text-red-400 font-mono" x-text="'(' + (selectedStep?.cache_miss_count || 0) + ' misses)'"></span>
                        </h4>
                        <div class="space-y-2">
                            <template x-for="(cache, idx) in selectedStep?.cache_calls || []" :key="idx">
                                <div class="bg-dark-900 p-3 rounded-lg border border-gray-800 flex items-center gap-3">
                                    <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase"
                                          :class="cache.hit ? 'bg-green-500/20 text-green-400' : 'bg-red-500/20 text-red-400'"
                                          x-text="cache.hit ? 'HIT' : 'MISS'"></span>
                                    <span class="px-2 py-0.5 rounded text-[10px] font-mono bg-dark-700 text-gray-400" x-text="cache.operation || cache.type || 'get'"></span>
                                    <span class="font-mono text-sm text-gray-300 truncate" x-text="cache.key"></span>
                                    <span class="text-xs text-gray-500 ml-auto" x-text="cache.ttl ? 'TTL: ' + cache.ttl + 's' : ''"></span>
                                </div>
                            </template>
                        </div>
                    </div>

                    <!-- HTTP Calls -->
                    <div id="section-http" x-show="selectedStep?.http_calls && selectedStep?.http_calls.length > 0">
                        <h4 class="text-xs uppercase text-gray-500 font-semibold mb-2 tracking-wider flex items-center gap-2">
                            <i data-feather="globe" class="w-3 h-3"></i>
                            HTTP Calls
                            <span class="text-brand-400 font-mono" x-text="'(' + (selectedStep?.http_calls?.length || 0) + ')'"></span>
                        </h4>
                        <div class="space-y-2">
                            <template x-for="(http, idx) in selectedStep?.http_calls || []" :key="idx">
                                <div class="bg-dark-900 p-3 rounded-lg border border-gray-800">
                                    <div class="flex items-center gap-2 mb-2">
                                        <span class="px-1.5 py-0.5 rounded text-[10px] font-bold bg-brand-500/20 text-brand-300" x-text="http.method || 'GET'"></span>
                                        <span class="font-mono text-sm text-gray-300 truncate" x-text="http.url"></span>
                                        <span class="ml-auto px-2 py-0.5 rounded text-xs font-mono"
                                              :class="(http.status || http.response_status) >= 400 ? 'bg-red-500/20 text-red-400' : 'bg-green-500/20 text-green-400'"
                                              x-text="http.status || http.response_status || '—'"></span>
                                        <span class="text-xs text-gray-500" x-text="(http.duration_ms || http.time || 0) + ' ms'"></span>
                                    </div>
                                    <div x-show="http.response_body" class="mt-2 pt-2 border-t border-gray-800">
                                        <pre class="font-mono text-xs text-blue-300 max-h-32 overflow-auto" x-text="typeof http.response_body === 'string' ? http.response_body : JSON.stringify(http.response_body, null, 2)"></pre>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>

                    <!-- Mail Calls -->
                    <div id="section-mail" x-show="selectedStep?.mail_calls && selectedStep?.mail_calls.length > 0">
                        <h4 class="text-xs uppercase text-gray-500 font-semibold mb-2 tracking-wider flex items-center gap-2">
                            <i data-feather="mail" class="w-3 h-3"></i>
                            Emails Sent
                            <span class="text-brand-400 font-mono" x-text="'(' + (selectedStep?.mail_calls?.length || 0) + ')'"></span>
                        </h4>
                        <div class="space-y-2">
                            <template x-for="(mail, idx) in selectedStep?.mail_calls || []" :key="idx">
                                <div class="bg-dark-900 p-3 rounded-lg border border-gray-800">
                                    <div class="flex items-center gap-2">
                                        <span class="text-sm text-gray-300" x-text="mail.to || mail.recipient"></span>
                                        <span class="text-gray-600">—</span>
                                        <span class="text-sm text-gray-400 truncate" x-text="mail.subject"></span>
                                    </div>
                                    <div class="text-xs text-gray-500 mt-1" x-text="mail.mailable || mail.class"></div>
                                </div>
                            </template>
                        </div>
                    </div>

                    <!-- Log Calls -->
                    <div id="section-logs" x-show="selectedStep?.log_calls && selectedStep?.log_calls.length > 0">
                        <h4 class="text-xs uppercase text-gray-500 font-semibold mb-2 tracking-wider flex items-center gap-2">
                            <i data-feather="file-text" class="w-3 h-3"></i>
                            Log Entries
                            <span class="text-brand-400 font-mono" x-text="'(' + (selectedStep?.log_calls?.length || 0) + ')'"></span>
                        </h4>
                        <div class="space-y-2">
                            <template x-for="(log, idx) in selectedStep?.log_calls || []" :key="idx">
                                <div class="bg-dark-900 p-3 rounded-lg border border-gray-800">
                                    <div class="flex items-center gap-2 mb-1">
                                        <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase"
                                              :class="{
                                                  'bg-red-500/20 text-red-400': log.level === 'error' || log.level === 'critical' || log.level === 'emergency',
                                                  'bg-yellow-500/20 text-yellow-400': log.level === 'warning',
                                                  'bg-blue-500/20 text-blue-400': log.level === 'info',
                                                  'bg-gray-500/20 text-gray-400': log.level === 'debug'
                                              }"
                                              x-text="log.level"></span>
                                    </div>
                                    <pre class="font-mono text-xs text-gray-300 whitespace-pre-wrap" x-text="log.message"></pre>
                                    <div x-show="log.context && Object.keys(log.context).length > 0" class="mt-2 pt-2 border-t border-gray-800">
                                        <pre class="font-mono text-[11px] text-gray-500" x-text="JSON.stringify(log.context, null, 2)"></pre>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>

                    <!-- State Snapshot -->
                    <div x-show="selectedStep?.state_snapshot && Object.keys(selectedStep?.state_snapshot || {}).length > 0">
                        <h4 class="text-xs uppercase text-gray-500 font-semibold mb-2 tracking-wider">State Snapshot</h4>
                        <pre class="bg-dark-900 p-4 rounded-lg font-mono text-sm overflow-x-auto border border-gray-800"><code class="text-yellow-200" x-text="formatJSON(selectedStep?.state_snapshot)"></code></pre>
                    </div>
                </div>

                <!-- Response Tab -->
                <div x-show="activeTab === 'response'">
                    <template x-if="selectedStep?.response_payload">
                        <pre class="bg-dark-900 p-4 rounded-lg font-mono text-sm overflow-x-auto border border-gray-800"><code class="text-blue-300" x-text="formatJSON(selectedStep?.response_payload)"></code></pre>
                    </template>
                    <div x-show="!selectedStep?.response_payload" class="text-center text-gray-500 py-10">No response payload recorded for this step.</div>
                </div>

                <!-- Error Tab -->
                <div x-show="activeTab === 'error'">
                    <div class="bg-red-500/10 border border-red-500/20 text-red-300 p-4 rounded-lg font-mono text-sm overflow-x-auto whitespace-pre-wrap" x-text="selectedStep?.error_reason || 'No error details captured.'"></div>
                </div>
            </div>
        </div>

        <!-- AI Prompt Modal -->
        <div x-show="showAiModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 backdrop-blur-sm" x-cloak @click.self="showAiModal = false">
            <div class="bg-dark-900 w-full max-w-3xl rounded-xl shadow-2xl border border-gray-700 m-4 flex flex-col max-h-[90vh]" @click.stop>
                <div class="flex justify-between items-center p-4 border-b border-gray-700 bg-dark-800 rounded-t-xl">
                    <h3 class="text-lg font-semibold text-white flex items-center gap-2">
                        <svg class="w-5 h-5 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                        AI Debugging Prompt
                    </h3>
                    <button @click="showAiModal = false" class="w-8 h-8 flex items-center justify-center rounded-lg bg-dark-700 text-gray-400 hover:text-white hover:bg-dark-600 transition">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>
                <div class="p-4 overflow-y-auto flex-grow">
                    <div class="flex items-center justify-between mb-3">
                        <p class="text-sm text-gray-400">Copy this prompt into Cursor, ChatGPT, or your preferred IDE AI assistant.</p>
                        <button @click="copyPrompt" class="flex items-center gap-2 px-3 py-1.5 rounded-lg bg-brand-500/20 text-brand-400 border border-brand-500/30 hover:bg-brand-500/30 transition text-sm font-medium">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                            Copy
                        </button>
                    </div>
                    <pre class="bg-dark-800 p-4 rounded-lg font-mono text-sm text-gray-300 break-words whitespace-pre-wrap border border-gray-700 max-h-[60vh] overflow-y-auto" x-text="aiPromptContent"></pre>
                </div>
            </div>
        </div>

        <!-- Replay Diff Modal -->
        <div x-show="showReplayModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm" x-cloak>
            <div class="glass-panel w-full max-w-5xl rounded-xl shadow-2xl border border-gray-700 m-4 flex flex-col max-h-[90vh]">
                <div class="flex justify-between items-center p-4 border-b border-gray-800">
                    <h3 class="text-lg font-semibold text-white flex items-center gap-2">
                        <i data-feather="repeat" class="w-5 h-5 text-brand-400"></i> Replay Results
                    </h3>
                    <button @click="showReplayModal = false" class="text-gray-400 hover:text-white"><i data-feather="x"></i></button>
                </div>
                <div class="p-4 overflow-y-auto flex-grow bg-dark-900">
                    <!-- Status match indicator -->
                    <div class="mb-4 flex items-center gap-3 text-sm">
                        <span class="text-gray-400">HTTP Status:</span>
                        <span x-text="replayData?.original?.status" class="font-mono text-gray-300"></span>
                        <span class="text-gray-600">→</span>
                        <span x-text="replayData?.replay?.status"
                              :class="replayData?.original?.status===replayData?.replay?.status ? 'text-green-400' : 'text-red-400'"
                              class="font-mono font-bold"></span>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <h4 class="text-sm text-gray-400 font-semibold mb-2">Original Response</h4>
                            <pre class="bg-dark-800 p-4 rounded-lg font-mono text-xs overflow-x-auto border border-gray-700"><code class="text-gray-300" x-text="formatJSON(replayData?.original?.body ?? replayData?.original)"></code></pre>
                        </div>
                        <div>
                            <h4 class="text-sm text-brand-400 font-semibold mb-2">Replayed Response</h4>
                            <pre class="bg-dark-800 p-4 rounded-lg font-mono text-xs overflow-x-auto border border-gray-700"><code class="text-blue-200" x-text="formatJSON(replayData?.replay?.body ?? replayData?.replay)"></code></pre>
                        </div>
                    </div>
                    <div class="mt-4" x-show="replayData?.diff && Object.keys(replayData.diff).length">
                        <h4 class="text-sm text-yellow-400 font-semibold mb-2">Structural Diff</h4>
                        <pre class="bg-dark-800 p-4 rounded-lg font-mono text-xs overflow-x-auto border border-yellow-500/20"><code class="text-yellow-200" x-text="formatJSON(replayData?.diff)"></code></pre>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

@endsection

@section('scripts')
<script>
    // Store trace data globally so it's available when Alpine initializes
    window.traceViewerData = {!! json_encode($trace->steps->toArray(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) !!};
    window.traceId = '{{ $trace->id }}';
    window.aiPromptUrl = '{{ route('trace-replay.ai.prompt', $trace->id) }}';
    window.replayUrl = '{{ route('trace-replay.replay', $trace->id) }}';

    document.addEventListener('alpine:init', () => {
        Alpine.data('traceViewer', () => ({
            selectedStep: null,
            activeTab: 'request',
            showAiModal: false,
            aiPromptContent: 'Generating...',
            showReplayModal: false,
            replayData: null,
            allSteps: window.traceViewerData || [],

            init() {
                if (this.allSteps.length > 0) {
                    const errorStep = this.allSteps.find(s => s.status === 'error');
                    this.selectStep(errorStep || this.allSteps[0]);
                }
            },

            selectStep(step) {
                this.selectedStep = step;
                this.activeTab = step && step.status === 'error' ? 'error' : 'request';
            },

            formatJSON(obj) {
                if (!obj) return 'No data available';
                if (typeof obj === 'string') {
                    try {
                        obj = JSON.parse(obj);
                    } catch (e) {
                        return obj;
                    }
                }
                return JSON.stringify(obj, null, 2);
            },

            async generateFixPrompt() {
                this.showAiModal = true;
                this.aiPromptContent = 'Generating expert debugging prompt…';

                try {
                    const token = document.querySelector('meta[name="csrf-token"]').content;
                    const response = await fetch(window.aiPromptUrl, {
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': token,
                            'X-Requested-With': 'XMLHttpRequest',
                            'Accept': 'application/json'
                        }
                    });
                    if (!response.ok) {
                        throw new Error('HTTP '+response.status);
                    }
                    const data = await response.json();
                    if (data.status !== 'success') {
                        throw new Error(data.message || 'Unable to generate prompt');
                    }
                    this.aiPromptContent = data.data.ai_response || data.data.prompt;
                } catch (e) {
                    this.aiPromptContent = 'Error generating prompt: ' + e.message;
                }
            },

            async replayRequest() {
                this.showReplayModal = true;
                this.replayData = { original: 'Running replay...', replay: 'Waiting...' };

                try {
                    const token = document.querySelector('meta[name="csrf-token"]').content;
                    const response = await fetch(window.replayUrl, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': token,
                            'X-Requested-With': 'XMLHttpRequest',
                            'Accept': 'application/json'
                        },
                        body: JSON.stringify({})
                    });
                    if (!response.ok) {
                        throw new Error('HTTP '+response.status);
                    }
                    const result = await response.json();
                    if (result.status === 'success') {
                        this.replayData = result.data;
                    } else {
                        throw new Error(result.message);
                    }
                } catch (e) {
                    this.replayData = { original: 'Failed', replay: e.message };
                }
            },

            copyPrompt() {
                navigator.clipboard.writeText(this.aiPromptContent);
                alert('Prompt copied to clipboard!');
            }
        }));
    });
</script>
@endsection
