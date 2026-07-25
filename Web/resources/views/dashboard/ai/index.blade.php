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

<div class="grid grid-cols-1 xl:grid-cols-3 gap-6">

    {{-- NLP Query Assistant (2/3 width) --}}
    @livewire('dashboard.ai-insights-query', [
        'queryText' => $queryText ?? '',
        'queryResult' => $queryResult ?? null,
        'aiError' => $aiError ?? null,
    ])

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
                        <div class="flex justify-end">
                            @livewire('dashboard.event-sentiment-action', [
                                'eventId' => $event->id,
                                'pendingComments' => $event->unprocessed_comment_count,
                                'buttonLabel' => 'Analyze ' . $event->unprocessed_comment_count . ' Comment(s)',
                                'fullWidth' => true,
                            ], key('ai-event-sentiment-action-' . $event->id))
                        </div>
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
