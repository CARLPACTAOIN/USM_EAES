@php
    $formatTime = static fn ($time) => $time ? \Carbon\Carbon::parse((string) $time)->format('g:i A') : null;
    $eventSchedule = static function ($event) use ($formatTime): string {
        $days = $event->eventDays->sortBy('day_number')->values();

        if ($days->isEmpty()) {
            return $event->start_date->format('M d, Y')
                . ($event->end_date && $event->end_date->ne($event->start_date) ? ' - ' . $event->end_date->format('M d, Y') : '');
        }

        $firstDay = $days->first();
        $lastDay = $days->last();
        $timeRange = collect([$formatTime($firstDay->start_time), $formatTime($firstDay->end_time)])->filter()->implode(' - ');

        return $days->count() === 1
            ? trim($firstDay->date->format('M d, Y') . ($timeRange ? " - {$timeRange}" : ''))
            : $firstDay->date->format('M d') . ' - ' . $lastDay->date->format('M d, Y') . ' - ' . $days->count() . ' days';
    };

    $pendingCount = $pendingEvalEvents->count();
    $submittedCount = $submittedEvaluations->count();
    $missedCount = $missedEvalEvents->count();
@endphp

<x-layouts.app :title="'My Evaluations - EAES'">
    <div class="portal-evaluations-page">

        <header class="portal-page-hero portal-evaluations-hero">
            <div>
                <p class="portal-page-kicker">Evaluation center</p>
                <h1>My Evaluations</h1>
                <p>Required event evaluations appear here after your attendance is recorded. Submit open evaluations before the deadline to validate attendance.</p>
                <div class="portal-page-meta">
                    <span class="portal-meta-chip portal-meta-chip--status">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M9 5h6m-7 4h8m-9 4h5m-6 8h12a2 2 0 0 0 2-2V7.5L14.5 2H6a2 2 0 0 0-2 2v15a2 2 0 0 0 2 2Z"/></svg>
                        {{ $pendingCount }} pending
                    </span>
                    <span class="portal-meta-chip portal-meta-chip--submitted">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
                        {{ $submittedCount }} submitted
                    </span>
                    <span class="portal-meta-chip {{ $missedCount ? 'portal-meta-chip--closed' : '' }}">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M12 6v6l4 2m5-2a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
                        {{ $missedCount }} closed
                    </span>
                </div>
            </div>
        </header>

        <div class="portal-evaluations-grid">
            <section class="portal-evaluations-card portal-evaluations-card--primary">
                <div class="portal-evaluations-card-head">
                    <div>
                        <p class="portal-page-kicker">Action needed</p>
                        <h2>Pending Evaluations</h2>
                    </div>
                    <span class="badge badge-pending">{{ $pendingCount }}</span>
                </div>

                <div class="portal-evaluations-list">
                    @forelse($pendingEvalEvents as $event)
                        @php($closesAt = \App\Support\EvaluationWindow::closesAt($event))
                        <a href="{{ route('portal.evaluation', $event) }}" class="portal-evaluations-row">
                            <span class="portal-evaluations-row-icon" aria-hidden="true">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M9 5h6m-7 4h8m-9 4h5m7 7H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h8.5L20 7.5V18a2 2 0 0 1-2 2Z"/></svg>
                            </span>
                            <span class="portal-evaluations-row-body">
                                <strong>{{ $event->title }}</strong>
                                <span>{{ $eventSchedule($event) }}</span>
                                <small>Closes {{ $closesAt?->format('M d, Y g:i A') ?? 'when the event window is available' }}</small>
                            </span>
                            <span class="portal-evaluations-row-action">Evaluate now</span>
                        </a>
                    @empty
                        <div class="empty-state py-10">
                            <svg class="empty-state-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m6 2a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
                            <p class="empty-state-title">No evaluations to complete</p>
                            <p class="empty-state-description">When you attend an approved event and its evaluation window opens, it will appear here.</p>
                        </div>
                    @endforelse
                </div>
            </section>

            <aside class="portal-evaluations-card portal-evaluations-guide">
                <div class="portal-evaluations-card-head">
                    <div>
                        <p class="portal-page-kicker">Attendance gate</p>
                        <h2>Status Guide</h2>
                    </div>
                </div>
                <div class="portal-checklist">
                    <div class="portal-check-item {{ $pendingCount === 0 ? 'is-complete' : '' }}">
                        <span class="portal-check-icon {{ $pendingCount === 0 ? 'is-complete' : 'is-pending' }}" aria-hidden="true">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m5 13 4 4L19 7"/></svg>
                        </span>
                        <span>
                            <span class="portal-check-label">Open evaluations</span>
                            <span class="portal-check-note">{{ $pendingCount ? 'Submit pending forms before their close time.' : 'No open forms need action.' }}</span>
                        </span>
                    </div>
                    <div class="portal-check-item {{ $submittedCount > 0 ? 'is-complete' : '' }}">
                        <span class="portal-check-icon {{ $submittedCount > 0 ? 'is-complete' : 'is-pending' }}" aria-hidden="true">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m5 13 4 4L19 7"/></svg>
                        </span>
                        <span>
                            <span class="portal-check-label">Validated attendance</span>
                            <span class="portal-check-note">Attendance becomes valid after submission.</span>
                        </span>
                    </div>
                    <div class="portal-check-item {{ $missedCount === 0 ? 'is-complete' : '' }}">
                        <span class="portal-check-icon {{ $missedCount === 0 ? 'is-complete' : 'is-pending' }}" aria-hidden="true">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m5 13 4 4L19 7"/></svg>
                        </span>
                        <span>
                            <span class="portal-check-label">Closed windows</span>
                            <span class="portal-check-note">{{ $missedCount ? 'Some attended events can no longer accept evaluations.' : 'No missed evaluation windows.' }}</span>
                        </span>
                    </div>
                </div>
            </aside>
        </div>

        <section class="portal-evaluations-card">
            <div class="portal-evaluations-card-head">
                <div>
                    <p class="portal-page-kicker">History</p>
                    <h2>Submitted Evaluations</h2>
                </div>
                <span class="badge badge-approved">{{ $submittedCount }}</span>
            </div>
            <div class="portal-evaluations-list">
                @forelse($submittedEvaluations as $evaluation)
                    <div class="portal-evaluations-row portal-evaluations-row--static">
                        <span class="portal-evaluations-row-icon portal-evaluations-row-icon--success" aria-hidden="true">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="m5 13 4 4L19 7"/></svg>
                        </span>
                        <span class="portal-evaluations-row-body">
                            <strong>{{ $evaluation->event?->title ?? 'Unknown Event' }}</strong>
                            <span>{{ $evaluation->event ? $eventSchedule($evaluation->event) : 'Event record unavailable' }}</span>
                            <small>Submitted {{ $evaluation->submitted_at?->format('M d, Y g:i A') ?? 'recently' }}</small>
                        </span>
                        <span class="portal-evaluations-row-action portal-evaluations-row-action--done">Validated</span>
                    </div>
                @empty
                    <div class="empty-state py-8">
                        <p class="empty-state-title">No submitted evaluations yet</p>
                        <p class="empty-state-description">Completed event evaluations will appear here for your reference.</p>
                    </div>
                @endforelse
            </div>
        </section>

        @if($missedCount > 0)
            <section class="portal-evaluations-card portal-evaluations-card--closed">
                <div class="portal-evaluations-card-head">
                    <div>
                        <p class="portal-page-kicker">Closed</p>
                        <h2>Closed Evaluation Windows</h2>
                    </div>
                    <span class="badge badge-rejected">{{ $missedCount }}</span>
                </div>
                <div class="portal-evaluations-list">
                    @foreach($missedEvalEvents as $event)
                        @php($closesAt = \App\Support\EvaluationWindow::closesAt($event))
                        <div class="portal-evaluations-row portal-evaluations-row--static">
                            <span class="portal-evaluations-row-icon portal-evaluations-row-icon--closed" aria-hidden="true">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M18.364 18.364A9 9 0 0 0 5.636 5.636m12.728 12.728A9 9 0 0 1 5.636 5.636m12.728 12.728L5.636 5.636"/></svg>
                            </span>
                            <span class="portal-evaluations-row-body">
                                <strong>{{ $event->title }}</strong>
                                <span>{{ $eventSchedule($event) }}</span>
                                <small>Closed {{ $closesAt?->format('M d, Y g:i A') ?? 'after the evaluation window' }}</small>
                            </span>
                            <span class="portal-evaluations-row-action portal-evaluations-row-action--closed">Closed</span>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif
    </div>
</x-layouts.app>
