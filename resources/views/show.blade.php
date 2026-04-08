@extends('trace-replay::layout')
@section('title', ($trace->name ?? 'Trace') . ' — TraceReplay')

@section('content')
<div class="flex flex-col lg:flex-row gap-6 h-[calc(100vh-8rem)]" x-data="traceViewer()">
    
    <!-- Left Column: Graph / Timeline -->
    <div class="lg:w-1/3 flex flex-col gap-4">
        <!-- Trace Header Summary -->
        <div class="glass-panel p-5 rounded-xl shadow-lg border-l-4 {{ $trace->status === 'success' ? 'border-green-500' : 'border-red-500' }}">
            <div class="flex justify-between items-start mb-2">
                <h2 class="text-xl font-bold text-white">{{ $trace->name }}</h2>
                <span class="text-sm font-mono text-gray-500">{{ substr($trace->id, 0, 8) }}</span>
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
        </div>

        <!-- Timeline -->
        <div class="glass-panel rounded-xl shadow-lg flex-grow overflow-y-auto p-5 scrollbar-thin">
            <h3 class="text-sm font-semibold text-gray-400 uppercase tracking-wider mb-4 border-b border-gray-800 pb-2">Execution Flow</h3>
            
            <div class="relative pl-3 mt-4 space-y-6 before:absolute before:inset-0 before:ml-5 before:-translate-x-px md:before:mx-auto md:before:translate-x-0 before:h-full before:w-0.5 before:bg-gradient-to-b before:from-transparent before:via-gray-700 before:to-transparent">
                
                @foreach ($trace->steps as $index => $step)
                <div class="relative flex items-center justify-between md:justify-normal md:odd:flex-row-reverse group is-active cursor-pointer"
                    @click="selectStep(allSteps[{{ $index }}])"
                    >
                    
                    <!-- Marker -->
                    <div class="flex items-center justify-center w-6 h-6 rounded-full border-2
                        {{ $step->status === 'success' ? 'bg-dark-900 border-green-500' : 'bg-dark-900 border-red-500' }}
                        z-10 absolute left-0 md:relative md:mx-auto shadow shrink-0
                        group-hover:scale-125 transition-transform"
                        :class="selectedStep && selectedStep.id === '{{ $step->id }}' ? 'ring-2 ring-brand-500 ring-offset-2 ring-offset-dark-900' : ''">
                    </div>

                    <!-- Label -->
                    <div class="glass-panel relative w-[calc(100%-3rem)] md:w-[calc(50%-1.5rem)] p-3 rounded shadow hover:bg-white/[0.02] transition"
                        :class="selectedStep && selectedStep.id === '{{ $step->id }}' ? 'border-brand-500/50 bg-brand-500/5' : ''">
                        <div class="flex items-center justify-between mb-1">
                            <span class="font-medium text-gray-200 text-sm">{{ $step->label }}</span>
                            <span class="text-xs text-gray-500">{{ $step->duration_ms }}ms</span>
                        </div>
                        @if($step->status === 'error')
                            <p class="text-xs text-red-400 line-clamp-1 mt-1 font-mono">Error occurred here</p>
                        @endif
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
                @if($trace->status === 'error')
                    <button @click="generateFixPrompt()" 
                            class="px-4 py-1.5 text-sm font-medium rounded-lg bg-gradient-to-r from-purple-600 to-indigo-600 text-white shadow-lg hover:from-purple-500 hover:to-indigo-500 transition-all flex items-center gap-2">
                        <i data-feather="cpu" class="w-4 h-4"></i> AI Fix Prompt
                    </button>
                @endif
                <button @click="replayRequest()" 
                        class="px-4 py-1.5 text-sm font-medium rounded-lg bg-white/10 text-white border border-white/10 hover:bg-white/20 transition-all flex items-center gap-2">
                    <i data-feather="play" class="w-4 h-4"></i> Replay
                </button>
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

            <!-- Content Area -->
            <div class="p-4 overflow-y-auto flex-grow bg-dark-900/50 space-y-4">

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
                    <div>
                        <h4 class="text-xs uppercase text-gray-500 font-semibold mb-2 tracking-wider">Request Payload</h4>
                        <pre class="bg-dark-900 p-4 rounded-lg font-mono text-sm overflow-x-auto border border-gray-800"><code class="text-green-300" x-text="formatJSON(selectedStep?.request_payload)"></code></pre>
                    </div>
                    <div x-show="selectedStep?.state_snapshot">
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
        <div x-show="showAiModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm" x-cloak>
            <div class="glass-panel w-full max-w-3xl rounded-xl shadow-2xl border border-gray-700 m-4 flex flex-col max-h-[90vh]">
                <div class="flex justify-between items-center p-4 border-b border-gray-800">
                    <h3 class="text-lg font-semibold text-white flex items-center gap-2">
                        <i data-feather="cpu" class="w-5 h-5 text-purple-400"></i> AI Debugging Prompt
                    </h3>
                    <button @click="showAiModal = false" class="text-gray-400 hover:text-white"><i data-feather="x"></i></button>
                </div>
                <div class="p-4 overflow-y-auto flex-grow relative bg-dark-900 items-start">
                    <p class="text-sm text-gray-400 mb-3">Copy this prompt into Cursor, ChatGPT, or your preferred IDE AI assistant.</p>
                    <button @click="copyPrompt" class="absolute top-16 right-6 p-2 rounded bg-dark-700 hover:bg-dark-600 border border-gray-600 transition" title="Copy to clipboard">
                        <i data-feather="copy" class="w-4 h-4"></i>
                    </button>
                    <pre class="bg-dark-800 p-4 rounded-lg font-mono text-sm text-gray-300 break-words whitespace-pre-wrap border border-gray-700" x-text="aiPromptContent"></pre>
                </div>
            </div>
        </div>

        <!-- Waterfall Chart (placed before the inspector, shown in a collapsible) -->
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
                        $offset = 0; // Could compute start offset from step start time if tracked
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
                    const response = await fetch(window.aiPromptUrl, {
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                            'Accept': 'application/json'
                        }
                    });
                    const data = await response.json();
                    this.aiPromptContent = data.data.ai_response || data.data.prompt;
                } catch (e) {
                    this.aiPromptContent = 'Error generating prompt: ' + e.message;
                }
            },

            async replayRequest() {
                this.showReplayModal = true;
                this.replayData = { original: 'Running replay...', replay: 'Waiting...' };

                try {
                    const response = await fetch(window.replayUrl, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                            'Accept': 'application/json'
                        },
                        body: JSON.stringify({})
                    });
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
