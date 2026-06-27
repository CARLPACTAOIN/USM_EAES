<x-layouts.app :title="'AI Insights — EAES'">

{{-- Page Header --}}
<div class="flex flex-wrap items-start justify-between gap-4 mb-8">
    <div>
        <p class="text-xs font-semibold uppercase tracking-widest text-(--color-accent) mb-1">Artificial Intelligence</p>
        <h1 class="text-2xl font-display text-(--color-text-primary)">AI Insights</h1>
        <p class="text-sm text-(--color-text-secondary) mt-1">
            Natural language query assistant and evaluation sentiment analysis powered by {{ config('services.ai.provider') === 'ollama' ? 'Ollama' : 'Gemini' }} NLP.
        </p>
    </div>
    <div class="flex items-center gap-2">
        <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-semibold border"
              style="background: linear-gradient(135deg, rgba(99,102,241,0.12), rgba(139,92,246,0.12)); color: #a78bfa; border-color: rgba(139,92,246,0.25);">
            <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M9.75 3.75L8.25 7.5 4.5 9l3.75 1.5 1.5 3.75 1.5-3.75L15 9l-3.75-1.5-1.5-3.75z
                         M19.5 13.5L18.75 15.75 16.5 16.5l2.25.75.75 2.25.75-2.25L22.5 16.5l-2.25-.75-.75-2.25z"/>
            </svg>
            Powered by {{ config('services.ai.provider') === 'ollama' ? 'Ollama' : 'Gemini' }}
        </span>
    </div>
</div>

<div class="grid grid-cols-1 xl:grid-cols-3 gap-6" x-data="{ showSuggestions: true }">

    {{-- NLP Query Assistant (2/3 width) --}}
    <section class="card xl:col-span-2">
        <div class="card-header flex items-center gap-3">
            <div class="w-8 h-8 rounded-lg flex items-center justify-center shrink-0"
                 style="background: linear-gradient(135deg, rgba(99,102,241,0.2), rgba(139,92,246,0.2)); border: 1px solid rgba(139,92,246,0.25);">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="color: #a78bfa;">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                </svg>
            </div>
            <div>
                <h2 class="font-semibold text-(--color-text-primary)">NLP Query Assistant</h2>
                <p class="text-xs text-(--color-text-tertiary) mt-0.5">Ask questions about events, attendance, and evaluations in plain English</p>
            </div>
        </div>

        <div class="card-body space-y-5">
            {{-- Query Form --}}
            <form method="POST" action="{{ route('dashboard.ai.query') }}" class="space-y-3">
                @csrf
                <label for="query" class="form-label">Your Question</label>
                <div class="flex flex-col sm:flex-row gap-3">
                    <div class="relative flex-1">
                        <div class="absolute inset-y-0 left-3 flex items-center pointer-events-none">
                            <svg class="w-4 h-4 text-(--color-text-tertiary)" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                      d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                            </svg>
                        </div>
                        <input
                            id="query"
                            name="query"
                            value="{{ $queryText }}"
                            maxlength="500"
                            class="form-input min-h-11 w-full pl-9"
                            placeholder="e.g. Show completed events this semester with attendance above 80%"
                            required
                            @focus="showSuggestions = false"
                        >
                    </div>
                    <button type="submit" class="btn btn-primary min-h-11 shrink-0 gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                  d="M9.75 3.75L8.25 7.5 4.5 9l3.75 1.5 1.5 3.75 1.5-3.75L15 9l-3.75-1.5-1.5-3.75z"/>
                        </svg>
                        Run Query
                    </button>
                </div>
                @error('query')
                    <p class="text-sm text-(--color-destructive) flex items-center gap-1.5">
                        <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        {{ $message }}
                    </p>
                @enderror

                {{-- Suggestion Chips --}}
                <div x-show="showSuggestions && !{{ $queryResult ? 'true' : 'false' }}" class="space-y-2">
                    <p class="text-xs text-(--color-text-tertiary) font-medium">Try asking:</p>
                    <div class="flex flex-wrap gap-2">
                        @foreach([
                            'Show completed events this month',
                            'Events with attendance above 75%',
                            'List all organizations with evaluation score above 4',
                            'Show pending evaluations',
                            'Events by LSG this semester',
                        ] as $suggestion)
                            <button type="button"
                                    onclick="document.getElementById('query').value = '{{ $suggestion }}'; this.closest('form').submit();"
                                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-medium border border-(--color-border) bg-(--color-surface-raised) text-(--color-text-secondary) hover:text-(--color-text-primary) hover:border-(--color-accent) transition-all cursor-pointer">
                                <svg class="w-3 h-3 text-(--color-accent) shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"/>
                                </svg>
                                {{ $suggestion }}
                            </button>
                        @endforeach
                    </div>
                </div>
            </form>

            {{-- Error State --}}
            @if($aiError)
                <div class="rounded-xl border border-red-500/20 bg-red-500/8 p-4 flex items-start gap-3" role="alert">
                    <div class="w-8 h-8 rounded-lg bg-red-500/15 border border-red-500/25 flex items-center justify-center shrink-0">
                        <svg class="w-4 h-4 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                    </div>
                    <div class="min-w-0">
                        <p class="text-sm font-semibold text-red-400">Query Error</p>
                        <p class="text-sm text-red-400/80 mt-0.5">{{ $aiError }}</p>
                    </div>
                </div>
            @endif

            {{-- Query Results --}}
            @if($queryResult)
                @php
                    $rows = $queryResult['results'] ?? [];
                    $columns = $queryResult['columns'] ?? [];
                    $rowCount = count($rows);
                @endphp

                {{-- Results meta bar --}}
                <div class="pt-4 border-t border-(--color-border)">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-xs font-semibold bg-(--color-accent)/10 text-(--color-accent) border border-(--color-accent)/20">
                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7h16M4 10h16M4 13h10"/>
                                </svg>
                                {{ str_replace('_', ' ', $queryResult['target_table']) }}
                            </span>
                            <span class="badge">{{ count($queryResult['applied_filters']) }} filters applied</span>
                            @if(count($queryResult['ignored_filters']) > 0)
                                <span class="badge badge-rejected">{{ count($queryResult['ignored_filters']) }} ignored</span>
                            @endif
                        </div>
                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-xs font-semibold bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                            {{ $rowCount }} {{ Str::plural('row', $rowCount) }} returned
                        </span>
                    </div>
                </div>

                {{-- Results Table --}}
                <div class="border border-(--color-border) rounded-xl overflow-hidden">
                    <div class="overflow-x-auto max-h-[400px] overflow-y-auto">
                        <table class="data-table w-full text-sm">
                            <thead class="sticky top-0 z-10">
                                <tr>
                                    @foreach($columns as $column)
                                        <th class="text-left px-4 py-3 text-xs font-semibold text-(--color-text-tertiary) uppercase tracking-wider whitespace-nowrap bg-(--color-surface-raised) border-b border-(--color-border)">
                                            {{ str_replace('_', ' ', $column) }}
                                        </th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-(--color-border)">
                                @forelse($rows as $idx => $row)
                                    <tr class="hover:bg-(--color-surface-raised) transition-colors {{ $idx % 2 === 1 ? 'bg-(--color-surface-raised)/40' : '' }}">
                                        @foreach($columns as $column)
                                            <td class="px-4 py-2.5 text-sm text-(--color-text-secondary) font-data whitespace-nowrap max-w-[200px] truncate">
                                                @php
                                                    $val = $row[$column] ?? null;
                                                    $display = is_bool($val) ? ($val ? 'Yes' : 'No') : ($val ?? '—');
                                                @endphp
                                                {{ $display }}
                                            </td>
                                        @endforeach
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="{{ max(count($columns), 1) }}">
                                            <div class="empty-state py-10">
                                                <svg class="empty-state-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                                                          d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                                </svg>
                                                <p class="empty-state-title">No matching records</p>
                                                <p class="empty-state-description">The scoped query returned no rows. Try rephrasing your question.</p>
                                            </div>
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                {{-- Filter Detail Chips --}}
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="rounded-xl border border-(--color-border) bg-(--color-surface-raised)/50 p-4">
                        <div class="flex items-center gap-2 mb-3">
                            <div class="w-1.5 h-4 rounded-full bg-(--color-accent)"></div>
                            <h3 class="text-sm font-semibold text-(--color-text-primary)">Applied Filters</h3>
                        </div>
                        @forelse($queryResult['applied_filters'] as $filter)
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-xs font-medium bg-(--color-accent)/10 text-(--color-accent) border border-(--color-accent)/20 mb-1.5 mr-1.5 font-data">
                                <span class="font-semibold">{{ $filter['field'] }}</span>
                                <span class="opacity-60">{{ $filter['operator'] }}</span>
                                <span>{{ $filter['value'] }}</span>
                            </span>
                        @empty
                            <p class="text-xs text-(--color-text-tertiary) italic">None applied</p>
                        @endforelse
                    </div>

                    @if(count($queryResult['ignored_filters']) > 0)
                    <div class="rounded-xl border border-(--color-border) bg-(--color-surface-raised)/50 p-4">
                        <div class="flex items-center gap-2 mb-3">
                            <div class="w-1.5 h-4 rounded-full bg-amber-500"></div>
                            <h3 class="text-sm font-semibold text-(--color-text-primary)">Ignored Filters</h3>
                        </div>
                        @foreach($queryResult['ignored_filters'] as $filter)
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-xs font-medium bg-amber-500/10 text-amber-400 border border-amber-500/20 mb-1.5 mr-1.5 font-data opacity-80">
                                <span class="font-semibold">{{ $filter['field'] ?? 'unknown' }}</span>
                                <span class="opacity-60">{{ $filter['operator'] ?? '=' }}</span>
                                <span>{{ $filter['value'] ?? 'N/A' }}</span>
                            </span>
                        @endforeach
                    </div>
                    @endif
                </div>
            @endif
        </div>
    </section>

    {{-- Sentiment Queue (1/3 width) --}}
    <section class="card">
        <div class="card-header flex items-center gap-3">
            <div class="w-8 h-8 rounded-lg flex items-center justify-center shrink-0"
                 style="background: rgba(16,185,129,0.12); border: 1px solid rgba(16,185,129,0.25);">
                <svg class="w-4 h-4 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"/>
                </svg>
            </div>
            <div>
                <h2 class="font-semibold text-(--color-text-primary)">Sentiment Queue</h2>
                <p class="text-xs text-(--color-text-tertiary) mt-0.5">Events with evaluation comments</p>
            </div>
        </div>

        <div class="card-body p-0 divide-y divide-(--color-border)">
            @forelse($events as $event)
                @php
                    $total = $event->positive_comment_count + $event->neutral_comment_count + $event->negative_comment_count;
                    $posPct = $total > 0 ? round(($event->positive_comment_count / $total) * 100) : 0;
                    $neuPct = $total > 0 ? round(($event->neutral_comment_count / $total) * 100) : 0;
                    $negPct = $total > 0 ? round(($event->negative_comment_count / $total) * 100) : 0;
                @endphp
                <div class="px-5 py-4 space-y-3">
                    {{-- Event header row --}}
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p class="font-medium text-(--color-text-primary) text-sm leading-snug truncate">{{ $event->title }}</p>
                            <p class="text-xs text-(--color-text-tertiary) mt-0.5">
                                {{ $event->organization?->acronym ?? 'N/A' }}
                                &middot; closes {{ $event->evaluation_window_closes_at?->format('M d, Y') ?? 'N/A' }}
                            </p>
                        </div>
                        <span class="badge {{ $event->evaluation_window_closed ? 'badge-approved' : 'badge-submitted' }} shrink-0 mt-0.5">
                            {{ $event->evaluation_window_closed ? 'Closed' : 'Open' }}
                        </span>
                    </div>

                    {{-- Sentiment Mini-Bar --}}
                    @if($total > 0)
                        <div class="space-y-1.5">
                            <div class="h-2 w-full rounded-full overflow-hidden flex gap-px" title="Positive: {{ $posPct }}% · Neutral: {{ $neuPct }}% · Negative: {{ $negPct }}%">
                                <div class="h-full bg-emerald-500 transition-all" style="width: {{ $posPct }}%"></div>
                                <div class="h-full bg-slate-400/60 transition-all" style="width: {{ $neuPct }}%"></div>
                                <div class="h-full bg-rose-500 transition-all" style="width: {{ $negPct }}%"></div>
                            </div>
                            <div class="flex items-center justify-between text-[10px] text-(--color-text-tertiary)">
                                <div class="flex items-center gap-2.5">
                                    <span class="flex items-center gap-1"><span class="w-1.5 h-1.5 rounded-full bg-emerald-500 inline-block"></span>{{ $posPct }}%</span>
                                    <span class="flex items-center gap-1"><span class="w-1.5 h-1.5 rounded-full bg-slate-400 inline-block"></span>{{ $neuPct }}%</span>
                                    <span class="flex items-center gap-1"><span class="w-1.5 h-1.5 rounded-full bg-rose-500 inline-block"></span>{{ $negPct }}%</span>
                                </div>
                                @if($event->unprocessed_comment_count > 0)
                                    <span class="font-semibold text-amber-400">{{ $event->unprocessed_comment_count }} pending</span>
                                @endif
                            </div>
                        </div>
                    @else
                        {{-- No sentiment data yet, show pending count --}}
                        <div class="flex items-center gap-2 text-xs text-(--color-text-tertiary)">
                            <div class="h-2 w-full rounded-full bg-(--color-surface-raised)"></div>
                        </div>
                        @if($event->unprocessed_comment_count > 0)
                            <p class="text-xs font-semibold text-amber-400">{{ $event->unprocessed_comment_count }} comment(s) pending analysis</p>
                        @else
                            <p class="text-xs text-(--color-text-tertiary) italic">No comments yet</p>
                        @endif
                    @endif

                    {{-- Analyze CTA --}}
                    @if($event->evaluation_window_closed && $event->unprocessed_comment_count > 0)
                        <form method="POST" action="{{ route('dashboard.events.sentiments.analyze', $event->id) }}">
                            @csrf
                            <button type="submit" class="btn btn-primary btn-sm w-full min-h-9 gap-1.5">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                          d="M9.75 3.75L8.25 7.5 4.5 9l3.75 1.5 1.5 3.75 1.5-3.75L15 9l-3.75-1.5-1.5-3.75z"/>
                                </svg>
                                Analyze {{ $event->unprocessed_comment_count }} Comment(s)
                            </button>
                        </form>
                    @endif
                </div>
            @empty
                <div class="empty-state py-10">
                    <svg class="empty-state-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                              d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"/>
                    </svg>
                    <p class="empty-state-title">No evaluation comments</p>
                    <p class="empty-state-description">Events with submitted evaluations will appear here for sentiment analysis.</p>
                </div>
            @endforelse
        </div>
    </section>

</div>
</x-layouts.app>
