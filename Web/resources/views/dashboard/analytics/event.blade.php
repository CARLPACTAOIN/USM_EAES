@php
    $eventData = $analytics['event'];
    $attendance = $analytics['attendance'];
    $evaluations = $analytics['evaluations'];
    $dailyAttendance = collect($analytics['daily_attendance']);
    $scopeRollups = collect($analytics['participant_scopes']);
    $scannerSync = $analytics['scanner_sync'];
    $comments = collect($analytics['sentiment_comments']);
    $demographics = collect($analytics['demographics']);
    $label = fn ($value) => \Illuminate\Support\Str::of($value ?: 'N/A')->replace('_', ' ')->title();
    $tabs = [
        'overview' => 'Overview',
        'attendance' => 'Attendance',
        'evaluations' => 'Evaluations',
        'sentiment' => 'Sentiment',
        'scopes' => 'Participant Scopes',
        'scanner' => 'Scanner & Sync',
        'exports' => 'Exports',
    ];
@endphp

<x-layouts.app :title="'Event Analytics - '.$event->title">
<div class="space-y-6" x-data="{ activeTab: (window.location.hash ? window.location.hash.substring(1) : new URLSearchParams(window.location.search).get('tab')) || 'overview' }">
    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
        <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2 text-xs text-(--color-text-tertiary) mb-2">
                <a href="{{ route('dashboard.events') }}" class="hover:text-(--color-accent)">Events</a>
                <span>/</span>
                <a href="{{ route('dashboard.analytics') }}" class="hover:text-(--color-accent)">Organization Analytics</a>
                <span>/</span>
                <span>Event Analytics</span>
            </div>
            <h1 class="text-2xl font-bold text-(--color-text-primary) tracking-tight">{{ $event->title }}</h1>
            <div class="mt-2 flex flex-wrap items-center gap-2 text-xs">
                <span class="inline-flex items-center rounded-full border border-(--color-border) bg-(--color-surface-raised) px-2.5 py-1 font-semibold text-(--color-text-secondary)">{{ $label($eventData['status']) }}</span>
                <span class="inline-flex items-center rounded-full border border-(--color-border) bg-(--color-surface-raised) px-2.5 py-1 font-semibold text-(--color-text-secondary)">{{ $label($eventData['lifecycle_phase']) }}</span>
                <span class="inline-flex items-center rounded-full border border-(--color-border) bg-(--color-surface-raised) px-2.5 py-1 font-semibold text-(--color-text-secondary)">{{ $label($eventData['event_domain']) }}</span>
                <span class="text-(--color-text-tertiary)">{{ $eventData['start_date'] }} to {{ $eventData['end_date'] }}</span>
            </div>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('dashboard.events.exports.attendance.pdf', $event) }}" class="btn btn-sm btn-secondary">Attendance PDF</a>
            <a href="{{ route('dashboard.events.exports.evaluations.excel', $event) }}" class="btn btn-sm btn-secondary">Evaluations XLSX</a>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <div class="card">
            <div class="card-body">
                <p class="text-xs font-semibold uppercase text-(--color-text-tertiary)">Attendance</p>
                <div class="mt-2 flex items-baseline gap-2">
                    <span class="text-3xl font-bold font-data text-(--color-text-primary)">{{ $attendance['attendance_rate'] }}%</span>
                    <span class="text-xs text-(--color-text-tertiary)">{{ $attendance['attended'] }} / {{ $attendance['expected_audience'] }}</span>
                </div>
                <p class="mt-1 text-xs text-(--color-text-secondary)">{{ $attendance['denominator_label'] }}</p>
            </div>
        </div>
        <div class="card">
            <div class="card-body">
                <p class="text-xs font-semibold uppercase text-(--color-text-tertiary)">Valid Attendance</p>
                <div class="mt-2 flex items-baseline gap-2">
                    <span class="text-3xl font-bold font-data text-(--color-text-primary)">{{ $attendance['valid_attendance_rate'] }}%</span>
                    <span class="text-xs text-(--color-text-tertiary)">{{ $attendance['valid_unique_attended'] }} / {{ $attendance['expected_audience'] }}</span>
                </div>
                <p class="mt-1 text-xs text-(--color-text-secondary)">{{ $attendance['invalid_or_pending_unique'] }} attended but not valid yet</p>
            </div>
        </div>
        <div class="card">
            <div class="card-body">
                <p class="text-xs font-semibold uppercase text-(--color-text-tertiary)">Evaluation Response</p>
                <div class="mt-2 flex items-baseline gap-2">
                    <span class="text-3xl font-bold font-data text-(--color-text-primary)">{{ $evaluations['response_rate_attended'] }}%</span>
                    <span class="text-xs text-(--color-text-tertiary)">{{ $evaluations['unique_evaluators'] }} / {{ $attendance['global_unique_attended'] }}</span>
                </div>
                <p class="mt-1 text-xs text-(--color-text-secondary)">Attended-to-evaluated funnel</p>
            </div>
        </div>
        <div class="card">
            <div class="card-body">
                <p class="text-xs font-semibold uppercase text-(--color-text-tertiary)">Sentiment Health</p>
                <div class="mt-2 flex items-baseline gap-2">
                    <span class="text-3xl font-bold font-data text-(--color-text-primary)">{{ $evaluations['sentiment_health']['label'] }}</span>
                </div>
                <p class="mt-1 text-xs text-(--color-text-secondary)">{{ $evaluations['sentiments']['negative'] }} negative / {{ $evaluations['total_submitted'] }} submitted</p>
            </div>
        </div>
    </div>

    <div class="border-b border-(--color-border) overflow-x-auto">
        <div class="flex min-w-max gap-1">
            @foreach($tabs as $key => $tabLabel)
                <button type="button"
                        @click="activeTab = '{{ $key }}'; history.replaceState(null, '', '#' + '{{ $key }}')"
                        class="px-3 py-2 text-sm font-semibold border-b-2 transition-colors"
                        :class="activeTab === '{{ $key }}' ? 'border-(--color-accent) text-(--color-accent)' : 'border-transparent text-(--color-text-tertiary) hover:text-(--color-text-primary)'">
                    {{ $tabLabel }}
                </button>
            @endforeach
        </div>
    </div>

    <section x-show="activeTab === 'overview'" x-cloak class="space-y-5">
        <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
            <div class="card lg:col-span-2">
                <div class="card-header">
                    <h2 class="font-semibold text-(--color-text-primary)">Event Context</h2>
                    <span class="text-xs text-(--color-text-tertiary)">PRD Epic 6 evidence</span>
                </div>
                <div class="card-body p-0">
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <tbody class="divide-y divide-(--color-border)">
                                <tr><th class="px-4 py-3 text-left text-xs uppercase text-(--color-text-tertiary)">Organizer</th><td class="px-4 py-3 text-(--color-text-primary)">{{ $eventData['organizer']['acronym'] ?: $eventData['organizer']['name'] }}</td></tr>
                                <tr><th class="px-4 py-3 text-left text-xs uppercase text-(--color-text-tertiary)">Proposal / PPA State</th><td class="px-4 py-3 text-(--color-text-primary)">{{ $label($eventData['status']) }} / {{ $eventData['proposal_state']['hardcopy_submitted'] ? 'Hardcopy submitted' : 'Hardcopy pending' }}</td></tr>
                                <tr><th class="px-4 py-3 text-left text-xs uppercase text-(--color-text-tertiary)">Schedule</th><td class="px-4 py-3 text-(--color-text-primary)">{{ $eventData['start_date'] }} to {{ $eventData['end_date'] }}</td></tr>
                                <tr><th class="px-4 py-3 text-left text-xs uppercase text-(--color-text-tertiary)">Venue</th><td class="px-4 py-3 text-(--color-text-primary)">{{ $eventData['location_details'] ?: $label($eventData['location_type']) }}</td></tr>
                                <tr><th class="px-4 py-3 text-left text-xs uppercase text-(--color-text-tertiary)">Expected Audience Source</th><td class="px-4 py-3 text-(--color-text-primary)">{{ $eventData['audience']['source_label'] }} ({{ $attendance['expected_audience'] }} denominator)</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="card">
                <div class="card-header">
                    <h2 class="font-semibold text-(--color-text-primary)">Operational Flags</h2>
                </div>
                <div class="card-body space-y-3 text-sm">
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-(--color-text-secondary)">Pending QR links</span>
                        <span class="font-semibold font-data text-(--color-text-primary)">{{ $scannerSync['pending_links']['pending'] }}</span>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-(--color-text-secondary)">Active scanner sessions</span>
                        <span class="font-semibold font-data text-(--color-text-primary)">{{ $scannerSync['sessions']['active'] }}</span>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-(--color-text-secondary)">Raw scans persisted</span>
                        <span class="font-semibold font-data text-(--color-text-primary)">{{ $scannerSync['raw_scans']['total'] }}</span>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-(--color-text-secondary)">Last scanner seen</span>
                        <span class="font-semibold font-data text-(--color-text-primary)">{{ $scannerSync['sessions']['last_seen_at'] ?: 'None' }}</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h2 class="font-semibold text-(--color-text-primary)">Attendee Demographics</h2>
                <span class="text-xs text-(--color-text-tertiary)">{{ $attendance['global_unique_attended'] }} attended</span>
            </div>
            <div class="card-body">
                <div class="flex flex-wrap gap-2">
                    @forelse($demographics as $demo)
                        <span class="inline-flex items-center gap-2 rounded-lg border border-(--color-border) bg-(--color-surface-raised) px-3 py-2 text-xs">
                            <span class="font-semibold text-(--color-text-primary)">{{ $demo['program_code'] }}</span>
                            <span class="font-data text-(--color-text-secondary)">{{ $demo['count'] }}</span>
                        </span>
                    @empty
                        <p class="text-sm text-(--color-text-tertiary)">No attendee demographics yet.</p>
                    @endforelse
                </div>
            </div>
        </div>
    </section>

    <section x-show="activeTab === 'attendance'" x-cloak class="space-y-5">
        <div class="card">
            <div class="card-header">
                <h2 class="font-semibold text-(--color-text-primary)">Daily EILO Attendance</h2>
                <span class="text-xs text-(--color-text-tertiary)">{{ $attendance['attended'] }} / {{ $attendance['expected_audience'] }} expected audience</span>
            </div>
            <div class="card-body p-0 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-(--color-surface-raised)">
                        <tr class="border-b border-(--color-border)">
                            <th class="px-4 py-3 text-left text-xs uppercase text-(--color-text-tertiary)">Day</th>
                            <th class="px-4 py-3 text-right text-xs uppercase text-(--color-text-tertiary)">Attended</th>
                            <th class="px-4 py-3 text-right text-xs uppercase text-(--color-text-tertiary)">Valid</th>
                            <th class="px-4 py-3 text-right text-xs uppercase text-(--color-text-tertiary)">Time In / Out</th>
                            <th class="px-4 py-3 text-right text-xs uppercase text-(--color-text-tertiary)">Late / Cutoff</th>
                            <th class="px-4 py-3 text-right text-xs uppercase text-(--color-text-tertiary)">Absent</th>
                            <th class="px-4 py-3 text-right text-xs uppercase text-(--color-text-tertiary)">Left Early</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-(--color-border)">
                        @forelse($dailyAttendance as $day)
                            <tr>
                                <td class="px-4 py-3">
                                    <p class="font-semibold text-(--color-text-primary)">{{ $day['label'] }}</p>
                                    <p class="text-xs text-(--color-text-tertiary)">{{ $day['date'] }} · {{ $day['start_time'] }}-{{ $day['end_time'] }}</p>
                                </td>
                                <td class="px-4 py-3 text-right font-data">{{ $day['attended'] }} / {{ $day['expected_audience'] }} ({{ $day['attendance_rate'] }}%)</td>
                                <td class="px-4 py-3 text-right font-data">{{ $day['valid_unique_attended'] }} / {{ $day['expected_audience'] }} ({{ $day['valid_attendance_rate'] }}%)</td>
                                <td class="px-4 py-3 text-right font-data">{{ $day['time_in_records'] }} / {{ $day['time_out_records'] }}</td>
                                <td class="px-4 py-3 text-right font-data">{{ $day['status_counts']['late'] }} / {{ $day['status_counts']['late_cutoff'] }}</td>
                                <td class="px-4 py-3 text-right font-data">{{ $day['status_counts']['absent'] }}</td>
                                <td class="px-4 py-3 text-right font-data">{{ $day['left_early_records'] }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-4 py-6 text-center text-sm text-(--color-text-tertiary)">No event days are configured.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h2 class="font-semibold text-(--color-text-primary)">Participant Drilldown</h2>
                <span class="text-xs text-(--color-text-tertiary)">{{ $participantRows->total() }} row(s)</span>
            </div>
            <div class="card-body border-b border-(--color-border)">
                <form method="GET" action="{{ route('dashboard.events.analytics', $event) }}" class="grid grid-cols-1 gap-3 md:grid-cols-6">
                    <input type="hidden" name="tab" value="attendance">
                    <input type="search" name="q" value="{{ $filters['q'] }}" placeholder="Search name, ID, email" class="form-input md:col-span-2">
                    <select name="scope_id" class="form-select">
                        <option value="">All scopes</option>
                        @foreach($scopeRollups as $scope)
                            <option value="{{ $scope['id'] }}" @selected($filters['scope_id'] === $scope['id'])>{{ $scope['name'] }}</option>
                        @endforeach
                    </select>
                    <select name="day_id" class="form-select">
                        <option value="">All days</option>
                        @foreach($dailyAttendance as $day)
                            <option value="{{ $day['id'] }}" @selected($filters['day_id'] === $day['id'])>{{ $day['label'] }} - {{ $day['date'] }}</option>
                        @endforeach
                    </select>
                    <select name="status" class="form-select">
                        @foreach(['all' => 'All statuses', 'attended' => 'Attended', 'present_on_time' => 'On time', 'late' => 'Late', 'late_cutoff' => 'Late cutoff', 'left_early' => 'Left early', 'absent' => 'Absent'] as $value => $text)
                            <option value="{{ $value }}" @selected($filters['status'] === $value)>{{ $text }}</option>
                        @endforeach
                    </select>
                    <select name="validation" class="form-select">
                        @foreach(['all' => 'All validation', 'valid' => 'Valid', 'invalid' => 'Invalid or pending', 'evaluated' => 'Evaluated', 'not_evaluated' => 'Missing evaluation'] as $value => $text)
                            <option value="{{ $value }}" @selected($filters['validation'] === $value)>{{ $text }}</option>
                        @endforeach
                    </select>
                    <div class="md:col-span-6 flex flex-wrap gap-2">
                        <button type="submit" class="btn btn-sm">Apply Filters</button>
                        <a href="{{ route('dashboard.events.analytics', ['event' => $event, 'tab' => 'attendance']) }}" class="btn btn-sm btn-secondary">Reset</a>
                    </div>
                </form>
            </div>
            <div class="card-body p-0 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-(--color-surface-raised)">
                        <tr class="border-b border-(--color-border)">
                            <th class="px-4 py-3 text-left text-xs uppercase text-(--color-text-tertiary)">Student</th>
                            <th class="px-4 py-3 text-left text-xs uppercase text-(--color-text-tertiary)">Scope</th>
                            <th class="px-4 py-3 text-left text-xs uppercase text-(--color-text-tertiary)">Status</th>
                            <th class="px-4 py-3 text-left text-xs uppercase text-(--color-text-tertiary)">Validation</th>
                            <th class="px-4 py-3 text-left text-xs uppercase text-(--color-text-tertiary)">Evaluation</th>
                            <th class="px-4 py-3 text-right text-xs uppercase text-(--color-text-tertiary)">Time In / Out</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-(--color-border)">
                        @forelse($participantRows as $row)
                            <tr>
                                <td class="px-4 py-3">
                                    <p class="font-semibold text-(--color-text-primary)">{{ $row['name'] }}</p>
                                    <p class="text-xs text-(--color-text-tertiary)">{{ $row['student_id_number'] ?: $row['email'] }} · {{ $row['program_code'] ?: 'N/A' }}</p>
                                    @unless($row['expected'])
                                        <p class="mt-1 text-[11px] font-semibold text-amber-500">Outside expected denominator</p>
                                    @endunless
                                </td>
                                <td class="px-4 py-3 text-xs text-(--color-text-secondary)">{{ $row['scope_names']->isNotEmpty() ? $row['scope_names']->join(', ') : 'No scope' }}</td>
                                <td class="px-4 py-3 text-xs font-semibold text-(--color-text-primary)">{{ $label($row['day_status']['status'] ?? $row['status']) }}</td>
                                <td class="px-4 py-3 text-xs">
                                    <span class="{{ $row['valid'] ? 'text-emerald-500' : 'text-amber-500' }} font-semibold">{{ $row['valid'] ? 'Valid' : 'Invalid or pending' }}</span>
                                    @if($row['force_validated'])
                                        <span class="block text-[11px] text-(--color-text-tertiary)">Force validated</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-xs font-semibold {{ $row['evaluation_submitted'] ? 'text-emerald-500' : 'text-(--color-text-tertiary)' }}">{{ $row['evaluation_submitted'] ? 'Submitted' : 'Missing' }}</td>
                                <td class="px-4 py-3 text-right text-xs font-data text-(--color-text-secondary)">{{ $row['time_in'] ?: 'None' }} / {{ $row['time_out'] ?: 'None' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-4 py-6 text-center text-sm text-(--color-text-tertiary)">No participants match the filters.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($participantRows->hasPages())
                <div class="card-footer">{{ $participantRows->links() }}</div>
            @endif
        </div>
    </section>

    <section x-show="activeTab === 'evaluations'" x-cloak class="space-y-5">
        <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
            <div class="card"><div class="card-body"><p class="text-xs uppercase text-(--color-text-tertiary)">Submitted</p><p class="mt-2 text-3xl font-bold font-data">{{ $evaluations['unique_evaluators'] }}</p><p class="text-xs text-(--color-text-secondary)">{{ $evaluations['unique_evaluators'] }} / {{ $attendance['global_unique_attended'] }} attended</p></div></div>
            <div class="card"><div class="card-body"><p class="text-xs uppercase text-(--color-text-tertiary)">Missing From Attended</p><p class="mt-2 text-3xl font-bold font-data">{{ $evaluations['missing_from_attended'] }}</p><p class="text-xs text-(--color-text-secondary)">{{ $evaluations['response_rate_attended'] }}% response rate</p></div></div>
            <div class="card"><div class="card-body"><p class="text-xs uppercase text-(--color-text-tertiary)">Average Rating</p><p class="mt-2 text-3xl font-bold font-data">{{ number_format($evaluations['average_rating'], 2) }}</p><p class="text-xs text-(--color-text-secondary)">Across all category scores</p></div></div>
        </div>
        <div class="card">
            <div class="card-header">
                <h2 class="font-semibold text-(--color-text-primary)">Category Averages and Distribution</h2>
            </div>
            <div class="card-body p-0 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-(--color-surface-raised)">
                        <tr class="border-b border-(--color-border)">
                            <th class="px-4 py-3 text-left text-xs uppercase text-(--color-text-tertiary)">Category</th>
                            <th class="px-4 py-3 text-right text-xs uppercase text-(--color-text-tertiary)">Average</th>
                            <th class="px-4 py-3 text-right text-xs uppercase text-(--color-text-tertiary)">Responses</th>
                            <th class="px-4 py-3 text-right text-xs uppercase text-(--color-text-tertiary)">1</th>
                            <th class="px-4 py-3 text-right text-xs uppercase text-(--color-text-tertiary)">2</th>
                            <th class="px-4 py-3 text-right text-xs uppercase text-(--color-text-tertiary)">3</th>
                            <th class="px-4 py-3 text-right text-xs uppercase text-(--color-text-tertiary)">4</th>
                            <th class="px-4 py-3 text-right text-xs uppercase text-(--color-text-tertiary)">5</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-(--color-border)">
                        @foreach($evaluations['category_averages'] as $category)
                            <tr>
                                <td class="px-4 py-3"><p class="font-semibold text-(--color-text-primary)">{{ $category['label'] }}</p><p class="text-xs text-(--color-text-tertiary)">{{ $category['description'] }}</p></td>
                                <td class="px-4 py-3 text-right font-data">{{ number_format($category['average'], 2) }}</td>
                                <td class="px-4 py-3 text-right font-data">{{ $category['responses'] }}</td>
                                @foreach(range(1, 5) as $score)
                                    <td class="px-4 py-3 text-right font-data">{{ $category['distribution'][$score] ?? 0 }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <section x-show="activeTab === 'sentiment'" x-cloak class="space-y-5">
        <div class="grid grid-cols-1 gap-3 md:grid-cols-4">
            @foreach(['positive' => 'Positive', 'neutral' => 'Neutral', 'negative' => 'Negative', 'unprocessed' => 'Unprocessed'] as $key => $text)
                <div class="card"><div class="card-body"><p class="text-xs uppercase text-(--color-text-tertiary)">{{ $text }}</p><p class="mt-2 text-3xl font-bold font-data">{{ $evaluations['sentiments'][$key] }}</p><p class="text-xs text-(--color-text-secondary)">{{ $evaluations['total_submitted'] ? round(($evaluations['sentiments'][$key] / $evaluations['total_submitted']) * 100, 2) : 0 }}% of submitted</p></div></div>
            @endforeach
        </div>
        <div class="card">
            <div class="card-header">
                <h2 class="font-semibold text-(--color-text-primary)">Comment Review Queue</h2>
                <span class="text-xs text-(--color-text-tertiary)">Confidence avg {{ $evaluations['sentiment_confidence']['average'] }}</span>
            </div>
            <div class="card-body p-0 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-(--color-surface-raised)">
                        <tr class="border-b border-(--color-border)">
                            <th class="px-4 py-3 text-left text-xs uppercase text-(--color-text-tertiary)">Comment</th>
                            <th class="px-4 py-3 text-left text-xs uppercase text-(--color-text-tertiary)">Student</th>
                            <th class="px-4 py-3 text-left text-xs uppercase text-(--color-text-tertiary)">Sentiment</th>
                            <th class="px-4 py-3 text-right text-xs uppercase text-(--color-text-tertiary)">Confidence</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-(--color-border)">
                        @forelse($comments as $comment)
                            <tr>
                                <td class="px-4 py-3 text-(--color-text-primary) max-w-xl">{{ $comment['comment'] }}</td>
                                <td class="px-4 py-3 text-xs text-(--color-text-secondary)">{{ $comment['student_name'] ?: 'Unknown' }}<br>{{ $comment['program_code'] ?: 'N/A' }}</td>
                                <td class="px-4 py-3 text-xs font-semibold">{{ $label($comment['sentiment']) }}</td>
                                <td class="px-4 py-3 text-right font-data">{{ $comment['confidence'] !== null ? number_format($comment['confidence'], 3) : 'N/A' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-4 py-6 text-center text-sm text-(--color-text-tertiary)">No written comments yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <section x-show="activeTab === 'scopes'" x-cloak class="card">
        <div class="card-header">
            <h2 class="font-semibold text-(--color-text-primary)">Participant Scope Rollups</h2>
            <span class="text-xs text-(--color-text-tertiary)">{{ $eventData['audience']['source_label'] }}</span>
        </div>
        <div class="card-body p-0 overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-(--color-surface-raised)">
                    <tr class="border-b border-(--color-border)">
                        <th class="px-4 py-3 text-left text-xs uppercase text-(--color-text-tertiary)">Scope</th>
                        <th class="px-4 py-3 text-left text-xs uppercase text-(--color-text-tertiary)">PPA / Capture</th>
                        <th class="px-4 py-3 text-right text-xs uppercase text-(--color-text-tertiary)">Audience</th>
                        <th class="px-4 py-3 text-right text-xs uppercase text-(--color-text-tertiary)">Attendance</th>
                        <th class="px-4 py-3 text-right text-xs uppercase text-(--color-text-tertiary)">Evaluations</th>
                        <th class="px-4 py-3 text-right text-xs uppercase text-(--color-text-tertiary)">Scanner Evidence</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-(--color-border)">
                    @forelse($scopeRollups as $scope)
                        <tr>
                            <td class="px-4 py-3"><p class="font-semibold text-(--color-text-primary)">{{ $scope['name'] }}</p><p class="text-xs text-(--color-text-tertiary)">{{ $label($scope['participation_role']) }} · {{ $label($scope['participant_type']) }}</p></td>
                            <td class="px-4 py-3 text-xs text-(--color-text-secondary)">{{ $label($scope['ppa_status']) }} / {{ $label($scope['capture_mode']) }}</td>
                            <td class="px-4 py-3 text-right font-data">{{ $scope['expected_audience'] }}</td>
                            <td class="px-4 py-3 text-right font-data">{{ $scope['attended'] }} / {{ $scope['expected_audience'] }} ({{ $scope['attendance_rate'] }}%)</td>
                            <td class="px-4 py-3 text-right font-data">{{ $scope['evaluation_submissions'] }} / {{ $scope['expected_audience'] }} ({{ $scope['evaluation_rate'] }}%)</td>
                            <td class="px-4 py-3 text-right text-xs font-data">{{ $scope['scanner_assignments'] }} assignments · {{ $scope['scanner_sessions'] }} sessions · {{ $scope['raw_scans'] }} scans</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-6 text-center text-sm text-(--color-text-tertiary)">No active analytics-enabled scopes.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section x-show="activeTab === 'scanner'" x-cloak class="space-y-5">
        <div class="grid grid-cols-1 gap-3 md:grid-cols-4">
            <div class="card"><div class="card-body"><p class="text-xs uppercase text-(--color-text-tertiary)">Assignments</p><p class="mt-2 text-3xl font-bold font-data">{{ $scannerSync['assignments']['total'] }}</p><p class="text-xs text-(--color-text-secondary)">{{ $scannerSync['assignments']['active'] }} active / {{ $scannerSync['assignments']['revoked'] }} revoked</p></div></div>
            <div class="card"><div class="card-body"><p class="text-xs uppercase text-(--color-text-tertiary)">Sessions</p><p class="mt-2 text-3xl font-bold font-data">{{ $scannerSync['sessions']['total'] }}</p><p class="text-xs text-(--color-text-secondary)">{{ $scannerSync['sessions']['active'] }} active / {{ $scannerSync['sessions']['expired'] }} expired</p></div></div>
            <div class="card"><div class="card-body"><p class="text-xs uppercase text-(--color-text-tertiary)">Raw Scans</p><p class="mt-2 text-3xl font-bold font-data">{{ $scannerSync['raw_scans']['total'] }}</p><p class="text-xs text-(--color-text-secondary)">{{ $scannerSync['raw_scans']['manual_entries'] }} manual / {{ $scannerSync['raw_scans']['unresolved'] }} unresolved</p></div></div>
            <div class="card"><div class="card-body"><p class="text-xs uppercase text-(--color-text-tertiary)">Pending QR</p><p class="mt-2 text-3xl font-bold font-data">{{ $scannerSync['pending_links']['pending'] }}</p><p class="text-xs text-(--color-text-secondary)">{{ $scannerSync['pending_links']['resolved'] }} resolved / {{ $scannerSync['pending_links']['flagged'] }} flagged</p></div></div>
        </div>

        <div class="grid grid-cols-1 gap-5 xl:grid-cols-2">
            <div class="card">
                <div class="card-header"><h2 class="font-semibold text-(--color-text-primary)">Scanner Assignments</h2></div>
                <div class="card-body p-0 overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-(--color-surface-raised)"><tr class="border-b border-(--color-border)"><th class="px-4 py-3 text-left text-xs uppercase text-(--color-text-tertiary)">Scanner</th><th class="px-4 py-3 text-left text-xs uppercase text-(--color-text-tertiary)">Scope</th><th class="px-4 py-3 text-left text-xs uppercase text-(--color-text-tertiary)">Status</th><th class="px-4 py-3 text-right text-xs uppercase text-(--color-text-tertiary)">Last Active</th></tr></thead>
                        <tbody class="divide-y divide-(--color-border)">
                            @forelse($scannerSync['assignments']['rows'] as $assignment)
                                <tr><td class="px-4 py-3"><p class="font-semibold text-(--color-text-primary)">{{ $assignment['label'] ?: 'Scanner assignment' }}</p><p class="text-xs text-(--color-text-tertiary)">{{ $assignment['scanner_name'] ?: 'Unassigned' }}</p></td><td class="px-4 py-3 text-xs text-(--color-text-secondary)">{{ $assignment['scope_name'] ?: 'Event' }}</td><td class="px-4 py-3 text-xs font-semibold">{{ $label($assignment['status']) }}</td><td class="px-4 py-3 text-right text-xs font-data">{{ $assignment['last_activated_at'] ?: 'None' }}</td></tr>
                            @empty
                                <tr><td colspan="4" class="px-4 py-6 text-center text-sm text-(--color-text-tertiary)">No scanner assignments.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="card">
                <div class="card-header"><h2 class="font-semibold text-(--color-text-primary)">Session Activity</h2></div>
                <div class="card-body p-0 overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-(--color-surface-raised)"><tr class="border-b border-(--color-border)"><th class="px-4 py-3 text-left text-xs uppercase text-(--color-text-tertiary)">Session</th><th class="px-4 py-3 text-left text-xs uppercase text-(--color-text-tertiary)">Device</th><th class="px-4 py-3 text-left text-xs uppercase text-(--color-text-tertiary)">Status</th><th class="px-4 py-3 text-right text-xs uppercase text-(--color-text-tertiary)">Last Seen</th></tr></thead>
                        <tbody class="divide-y divide-(--color-border)">
                            @forelse($scannerSync['sessions']['rows'] as $session)
                                <tr><td class="px-4 py-3"><p class="font-semibold text-(--color-text-primary)">{{ $session['label'] ?: 'Scanner session' }}</p><p class="text-xs text-(--color-text-tertiary)">{{ $session['scanner_name'] ?: 'Unknown scanner' }}</p></td><td class="px-4 py-3 text-xs text-(--color-text-secondary)">{{ $session['device_id'] ?: 'No device' }}</td><td class="px-4 py-3 text-xs font-semibold">{{ $label($session['status']) }}</td><td class="px-4 py-3 text-right text-xs font-data">{{ $session['last_seen_at'] ?: 'None' }}</td></tr>
                            @empty
                                <tr><td colspan="4" class="px-4 py-6 text-center text-sm text-(--color-text-tertiary)">No scanner sessions.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </section>

    <section x-show="activeTab === 'exports'" x-cloak class="card">
        <div class="card-header">
            <h2 class="font-semibold text-(--color-text-primary)">Verified Exports</h2>
            <span class="text-xs text-(--color-text-tertiary)">Timestamped PDF and Excel outputs</span>
        </div>
        <div class="card-body grid grid-cols-1 gap-3 md:grid-cols-2">
            <a href="{{ route('dashboard.events.exports.attendance.pdf', $event) }}" class="rounded-lg border border-(--color-border) bg-(--color-surface-raised) px-4 py-3 hover:border-(--color-accent)">
                <p class="font-semibold text-(--color-text-primary)">Attendance PDF</p>
                <p class="text-xs text-(--color-text-secondary)">Validated attendance summary with denominator context.</p>
            </a>
            <a href="{{ route('dashboard.events.exports.attendance.excel', $event) }}" class="rounded-lg border border-(--color-border) bg-(--color-surface-raised) px-4 py-3 hover:border-(--color-accent)">
                <p class="font-semibold text-(--color-text-primary)">Attendance XLSX</p>
                <p class="text-xs text-(--color-text-secondary)">Spreadsheet attendance records and EILO timestamps.</p>
            </a>
            <a href="{{ route('dashboard.events.exports.evaluations.pdf', $event) }}" class="rounded-lg border border-(--color-border) bg-(--color-surface-raised) px-4 py-3 hover:border-(--color-accent)">
                <p class="font-semibold text-(--color-text-primary)">Evaluations PDF</p>
                <p class="text-xs text-(--color-text-secondary)">Evaluation response summary and submitted feedback evidence.</p>
            </a>
            <a href="{{ route('dashboard.events.exports.evaluations.excel', $event) }}" class="rounded-lg border border-(--color-border) bg-(--color-surface-raised) px-4 py-3 hover:border-(--color-accent)">
                <p class="font-semibold text-(--color-text-primary)">Evaluations XLSX</p>
                <p class="text-xs text-(--color-text-secondary)">Raw evaluation scores, comments, and sentiment fields.</p>
            </a>
        </div>
    </section>
</div>
</x-layouts.app>
