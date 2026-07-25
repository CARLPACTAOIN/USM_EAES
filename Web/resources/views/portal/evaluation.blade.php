@php
    $windowHours = (int) config('services.eaes.evaluation_window_hours', 24);
    $windowOpenAt = $windowCloseAt?->copy()->subHours($windowHours);
    $now = now();
    $windowTotalSeconds = $windowOpenAt && $windowCloseAt
        ? max(1, (int) $windowOpenAt->diffInSeconds($windowCloseAt, false))
        : max(1, $windowHours * 3600);
    $windowRemainingSeconds = $windowCloseAt
        ? max(0, (int) $now->diffInSeconds($windowCloseAt, false))
        : 0;
    $remainingPercent = $windowOpen
        ? max(0, min(100, (int) round(($windowRemainingSeconds / $windowTotalSeconds) * 100)))
        : 0;
    $displayPercent = $existing ? 100 : $remainingPercent;
    $remainingHours = intdiv($windowRemainingSeconds, 3600);
    $remainingMinutes = intdiv($windowRemainingSeconds % 3600, 60);
    $deadlineTone = $existing
        ? 'submitted'
        : (! $windowOpen ? 'closed' : ($windowRemainingSeconds <= 7200 ? 'critical' : ($windowRemainingSeconds <= 21600 ? 'warning' : 'open')));
    $deadlineLabel = match ($deadlineTone) {
        'submitted' => 'Submitted',
        'closed' => 'Closed',
        'critical' => 'Critical',
        'warning' => 'Closing soon',
        default => 'Open',
    };
    $countdownFallback = match ($deadlineTone) {
        'submitted' => 'Submitted',
        'closed' => 'No time remaining',
        default => "{$remainingHours}h {$remainingMinutes}m left",
    };
    $eventDays = $event->eventDays->sortBy('day_number')->values();
    $formatTime = static fn ($time) => $time ? \Carbon\Carbon::parse((string) $time)->format('g:i A') : null;

    if ($eventDays->isNotEmpty()) {
        $firstDay = $eventDays->first();
        $lastDay = $eventDays->last();
        $firstTime = collect([$formatTime($firstDay->start_time), $formatTime($firstDay->end_time)])->filter()->implode(' - ');
        $scheduleSummary = $eventDays->count() === 1
            ? trim($firstDay->date->format('M d, Y') . ($firstTime ? " - {$firstTime}" : ''))
            : $firstDay->date->format('M d') . ' - ' . $lastDay->date->format('M d, Y') . ' - ' . $eventDays->count() . ' days';
    } else {
        $scheduleSummary = $event->start_date->format('M d, Y')
            . ($event->end_date && $event->end_date->ne($event->start_date) ? ' - ' . $event->end_date->format('M d, Y') : '');
    }

    $questionCount = count($categories);
    $validationState = $existing ? 'Attendance validated' : ($windowOpen ? 'Pending evaluation' : 'Window closed');
@endphp

<x-layouts.app :title="'Evaluation — ' . $event->title . ' — EAES'">
    <div class="portal-evaluation-page"
         x-data="{
            closeIso: @js($windowCloseAt?->toIso8601String()),
            openIso: @js($windowOpenAt?->toIso8601String()),
            submitted: @js((bool) $existing),
            remainingSeconds: @js($windowRemainingSeconds),
            percent: @js($displayPercent),
            timer: null,
            init() {
                this.tick();
                this.timer = window.setInterval(() => this.tick(), 30000);
            },
            tick() {
                if (this.submitted) {
                    this.remainingSeconds = 0;
                    this.percent = 100;
                    return;
                }

                if (! this.closeIso || ! this.openIso) {
                    this.remainingSeconds = 0;
                    this.percent = 0;
                    return;
                }

                const closeAt = new Date(this.closeIso);
                const openAt = new Date(this.openIso);
                const now = new Date();
                const total = Math.max(1, Math.floor((closeAt - openAt) / 1000));
                this.remainingSeconds = Math.max(0, Math.floor((closeAt - now) / 1000));
                this.percent = Math.max(0, Math.min(100, Math.round((this.remainingSeconds / total) * 100)));
            },
            get remainingLabel() {
                if (this.submitted) {
                    return 'Submitted';
                }

                if (this.remainingSeconds <= 0) {
                    return 'No time remaining';
                }

                const hours = Math.floor(this.remainingSeconds / 3600);
                const minutes = Math.floor((this.remainingSeconds % 3600) / 60);
                return hours + 'h ' + minutes + 'm left';
            },
            get urgencyLabel() {
                if (this.submitted) {
                    return 'Submitted';
                }

                if (this.remainingSeconds <= 0) {
                    return 'Closed';
                }

                if (this.remainingSeconds <= 7200) {
                    return 'Critical';
                }

                if (this.remainingSeconds <= 21600) {
                    return 'Closing soon';
                }

                return 'Open';
            },
            get urgencyClass() {
                if (this.submitted) {
                    return 'portal-evaluation-status-card--submitted';
                }

                if (this.remainingSeconds <= 0) {
                    return 'portal-evaluation-status-card--closed';
                }

                if (this.remainingSeconds <= 7200) {
                    return 'portal-evaluation-status-card--critical';
                }

                if (this.remainingSeconds <= 21600) {
                    return 'portal-evaluation-status-card--warning';
                }

                return 'portal-evaluation-status-card--open';
            },
            get urgencyChipClass() {
                return 'portal-meta-chip--' + this.urgencyClass.replace('portal-evaluation-status-card--', '');
            }
         }">


        <header class="portal-page-hero portal-evaluation-hero">
            <div>
                <p class="portal-page-kicker">Student evaluation</p>
                <h1>{{ $event->title }}</h1>
                <p>Complete the locked event form to validate your attendance record.</p>
                <div class="portal-page-meta">
                    <span class="portal-meta-chip">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M9 5h6m-7 4h8m-9 4h5m-6 8h12a2 2 0 0 0 2-2V7.5L14.5 2H6a2 2 0 0 0-2 2v15a2 2 0 0 0 2 2Z"/></svg>
                        {{ $questionCount }} {{ Str::plural('question', $questionCount) }}
                    </span>
                    <span class="portal-meta-chip">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2Z"/></svg>
                        {{ $scheduleSummary }}
                    </span>
                    <span class="portal-meta-chip portal-meta-chip--status portal-meta-chip--{{ $deadlineTone }}" :class="urgencyChipClass">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M12 6v6l4 2m5-2a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
                        <span x-text="urgencyLabel">{{ $deadlineLabel }}</span>
                    </span>
                </div>
            </div>
        </header>

        <div class="portal-layout portal-layout-wide portal-evaluation-layout">
            <main class="portal-evaluation-main">
                @if($errors->any())
                    <div class="portal-evaluation-alert" role="alert">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 9v3.75m0 3.75h.008v.008H12V16.5Zm8.25 2.25-7.1-14.2a1.25 1.25 0 0 0-2.3 0l-7.1 14.2A1.25 1.25 0 0 0 4.86 20.5h14.28a1.25 1.25 0 0 0 1.11-1.75Z"/></svg>
                        <div>
                            <strong>Please review your answers.</strong>
                            <span>Some required ratings are missing or invalid.</span>
                        </div>
                    </div>
                @endif

                @if($existing)
                    <section class="portal-evaluation-state portal-evaluation-state--submitted">
                        <div class="portal-evaluation-state-icon" aria-hidden="true">
                            <svg class="w-9 h-9" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 12l2 2 4-4m6 2a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
                        </div>
                        <div>
                            <span class="portal-evaluation-state-badge">Complete</span>
                            <h2>Evaluation Submitted</h2>
                            <p>
                                You submitted your evaluation on {{ $existing->submitted_at?->format('M d, Y g:i A') ?? 'recorded submission time' }}.
                                Your attendance has been validated.
                            </p>
                            <a href="{{ route('portal') }}" class="btn btn-secondary">Return to Portal</a>
                        </div>
                    </section>
                @elseif(!$windowOpen)
                    <section class="portal-evaluation-state portal-evaluation-state--closed">
                        <div class="portal-evaluation-state-icon" aria-hidden="true">
                            <svg class="w-9 h-9" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M18.364 18.364A9 9 0 0 0 5.636 5.636m12.728 12.728A9 9 0 0 1 5.636 5.636m12.728 12.728L5.636 5.636"/></svg>
                        </div>
                        <div>
                            <span class="portal-evaluation-state-badge">Deadline passed</span>
                            <h2>Window Closed</h2>
                            <p>
                                The evaluation window for this event has closed.
                                @if($windowCloseAt)
                                    The deadline was {{ $windowCloseAt->format('M d, Y g:i A') }}.
                                @endif
                            </p>
                            <a href="{{ route('portal') }}" class="btn btn-secondary">Return to Portal</a>
                        </div>
                    </section>
                @else
                    <form method="POST" action="{{ route('portal.evaluation.submit') }}" class="portal-evaluation-form"
                          x-data="confirmAction" @submit.prevent="askConfirm('Are you sure you want to submit this evaluation? This action cannot be undone.', $el)">
                        @csrf
                        <input type="hidden" name="event_id" value="{{ $event->id }}">

                        <div class="portal-evaluation-form-head">
                            <div>
                                <p class="portal-page-kicker">Required ratings</p>
                                <h2>Rate this event</h2>
                                <p>Use the 1-5 scale for each item. Required questions are marked with an asterisk.</p>
                            </div>
                            <div class="portal-evaluation-scale-key" aria-label="Rating scale guide">
                                <span>1 Poor</span>
                                <span>5 Excellent</span>
                            </div>
                        </div>

                        <div class="portal-evaluation-question-list">
                            @foreach($categories as $key => $category)
                                @php($scaleLabels = $category['scale_labels'] ?? [])
                                <section class="portal-evaluation-question">
                                    <div class="portal-evaluation-question-top">
                                        <span class="portal-evaluation-question-number">Q{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
                                        <div>
                                            <label class="portal-evaluation-question-label" id="question-label-{{ $key }}">
                                                {{ $category['label'] }}
                                                @if($category['required'])
                                                    <span aria-label="required">*</span>
                                                @endif
                                            </label>
                                            @if(! empty($category['description']))
                                                <p>{{ $category['description'] }}</p>
                                            @endif
                                        </div>
                                    </div>

                                    <div class="portal-evaluation-likert" role="radiogroup" aria-labelledby="question-label-{{ $key }}">
                                        @for($i = (int) $category['scale_min']; $i <= (int) $category['scale_max']; $i++)
                                            <div class="portal-evaluation-likert-option">
                                                <input type="radio"
                                                       name="section_scores[{{ $key }}]"
                                                       id="rating_{{ $key }}_{{ $i }}"
                                                       value="{{ $i }}"
                                                       {{ old("section_scores.$key") == $i ? 'checked' : '' }}
                                                       {{ $category['required'] ? 'required' : '' }}>
                                                <label for="rating_{{ $key }}_{{ $i }}">
                                                    <span>{{ $i }}</span>
                                                    <small>{{ $scaleLabels[(string) $i] ?? '' }}</small>
                                                </label>
                                            </div>
                                        @endfor
                                    </div>
                                    @error("section_scores.$key") <p class="form-error" role="alert">{{ $message }}</p> @enderror
                                </section>
                            @endforeach

                            <section class="portal-evaluation-comment">
                                <label for="open_comment" class="portal-evaluation-question-label">Additional Comments</label>
                                <p>Optional open comment for details that ratings cannot capture.</p>
                                <textarea name="open_comment" id="open_comment" rows="4"
                                          placeholder="Share your thoughts about this event..."
                                          class="form-input @error('open_comment') form-input-error @enderror"
                                          maxlength="2000">{{ old('open_comment') }}</textarea>
                                <span class="form-helper">Comments can be in English, Tagalog, or Taglish.</span>
                                @error('open_comment') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                            </section>
                        </div>

                        <div class="portal-evaluation-actions">
                            <a href="{{ route('portal') }}" class="btn btn-secondary">Cancel</a>
                            <button type="submit" class="btn btn-primary">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>
                                Submit Evaluation
                            </button>
                        </div>

                        <template x-if="showConfirm">
                            <div class="modal-backdrop" @click.self="cancelConfirm()" @keydown.escape.window="cancelConfirm()">
                                <div class="modal-content p-6">
                                    <div class="text-center">
                                        <div class="w-12 h-12 rounded-full flex items-center justify-center mx-auto mb-4" style="background-color: var(--color-warning-light);">
                                            <svg class="w-6 h-6" style="color: var(--color-warning);" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>
                                        </div>
                                        <h3 class="text-lg font-semibold text-(--color-text-primary) mb-2">Confirm Submission</h3>
                                        <p class="text-sm text-(--color-text-secondary)" x-text="confirmMessage"></p>
                                    </div>
                                    <div class="flex gap-3 mt-6 justify-center">
                                        <button type="button" @click="cancelConfirm()" class="btn btn-secondary">Cancel</button>
                                        <button type="button" @click="doConfirm()" class="btn btn-primary">Yes, Submit</button>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </form>
                @endif
            </main>

            <aside class="portal-side-panel portal-evaluation-side" aria-label="Evaluation status">
                <section class="portal-evaluation-status-card portal-evaluation-status-card--{{ $deadlineTone }}" :class="urgencyClass">
                    <div class="portal-evaluation-status-top">
                        <span>Evaluation window</span>
                        <strong x-text="urgencyLabel">{{ $deadlineLabel }}</strong>
                    </div>
                    <div class="portal-evaluation-countdown">
                        <span>Time remaining</span>
                        <strong x-text="remainingLabel">{{ $countdownFallback }}</strong>
                    </div>
                    <div class="portal-evaluation-progress"
                         role="progressbar"
                         aria-label="Evaluation time remaining"
                         aria-valuemin="0"
                         aria-valuemax="100"
                         aria-valuenow="{{ $displayPercent }}"
                         :aria-valuenow="percent">
                        <span style="width: {{ $displayPercent }}%" :style="'width: ' + percent + '%'"></span>
                    </div>
                    <dl class="portal-evaluation-deadline">
                        <div>
                            <dt>Closes</dt>
                            <dd>{{ $windowCloseAt?->format('M d, Y g:i A') ?? 'Unavailable' }}</dd>
                        </div>
                        <div>
                            <dt>Window duration</dt>
                            <dd>{{ $windowHours }} hours</dd>
                        </div>
                    </dl>
                </section>

                <section class="portal-evaluation-summary">
                    <h2>Event Summary</h2>
                    <dl>
                        <div>
                            <dt>Schedule</dt>
                            <dd>{{ $scheduleSummary }}</dd>
                        </div>
                        <div>
                            <dt>Questions</dt>
                            <dd>{{ $questionCount }} Likert {{ Str::plural('item', $questionCount) }}</dd>
                        </div>
                        <div>
                            <dt>Attendance</dt>
                            <dd>{{ $validationState }}</dd>
                        </div>
                        <div>
                            <dt>Deadline</dt>
                            <dd>{{ $deadlineLabel }}</dd>
                        </div>
                    </dl>
                </section>

                <section class="portal-info-callout portal-evaluation-callout">
                    <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 6v6l3 3m6-3a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
                    <div>
                        <strong>{{ $existing ? 'Attendance is already validated.' : ($windowOpen ? 'Submit before the deadline.' : 'The deadline has passed.') }}</strong>
                        <span>{{ $existing ? 'No further action is needed for this event.' : ($windowOpen ? 'Your attendance becomes valid after this evaluation is submitted.' : 'This event can no longer accept student evaluations.') }}</span>
                    </div>
                </section>
            </aside>
        </div>
    </div>
</x-layouts.app>
