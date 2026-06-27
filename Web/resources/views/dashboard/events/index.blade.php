<x-layouts.app :title="'Event Proposals — EAES'">
    @php
        $statusFilters = $user->hasRole('Super Admin (OSA)')
            ? ['submitted', 'under_review', 'approved', 'rejected', 'completed']
            : ['draft', 'submitted', 'under_review', 'approved', 'rejected', 'completed'];
    @endphp

    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-8">
        <div>
            <h1 class="text-2xl font-display text-(--color-text-primary)">Event Proposals</h1>
            <p class="text-sm text-(--color-text-secondary) mt-1">Manage PPA event proposals</p>
        </div>
        @if($canCreateProposals)
        <button type="button" onclick="const modal = document.getElementById('create-event-modal'); modal.showModal(); setTimeout(() => document.getElementById('title')?.focus(), 50)" class="btn btn-primary shrink-0">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
            New Event
        </button>
        @endif
    </div>

    {{-- Status Filter --}}
    <div class="flex flex-wrap gap-2 mb-6">
        <a href="{{ route('dashboard.events') }}"
           class="btn btn-sm {{ !request('status') ? 'btn-primary' : 'btn-secondary' }}">All</a>
        @foreach($statusFilters as $status)
        <a href="{{ route('dashboard.events', ['status' => $status]) }}"
           class="btn btn-sm {{ request('status') === $status ? 'btn-primary' : 'btn-secondary' }}">
            {{ ucwords(str_replace('_', ' ', $status)) }}
        </a>
        @endforeach
    </div>

    {{-- Scanner Link Flash --}}
    @if(session('scanner_link'))
    <div class="card mb-6" style="border-left: 4px solid var(--color-accent);">
        <div class="card-body">
            <h3 class="font-semibold text-(--color-text-primary) mb-2">Scanner Deep-Link Generated</h3>
            <div class="flex items-center gap-2">
                <input type="text" value="{{ session('scanner_link') }}" id="scanner-link-input"
                       class="form-input font-data text-sm flex-1" readonly>
                <button type="button" onclick="navigator.clipboard.writeText(document.getElementById('scanner-link-input').value)"
                        class="btn btn-secondary btn-sm shrink-0">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                    Copy
                </button>
            </div>
            <p class="text-xs text-(--color-text-tertiary) mt-2">Share this link with your scanner operators. Opens directly in the EAES Scanner app.</p>
        </div>
    </div>
    @endif

    {{-- Events Table --}}
    <div class="card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Event</th>
                        <th>Organization</th>
                        <th>Dates</th>
                        <th>PPA Packet</th>
                        <th>Status</th>
                        <th>Exports</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($events as $event)
                    @php
                        $target = $event->target_demographics ?? [];
                        $budget = $event->budget_allocations ?? [];
                        $eventDaysSummary = $event->eventDays
                            ->sortBy('day_number')
                            ->map(function ($day) {
                                $timeRange = trim(substr((string) $day->start_time, 0, 5) . ' - ' . substr((string) $day->end_time, 0, 5));

                                return 'D' . $day->day_number . ': ' . ($day->date?->format('M d, Y') ?? 'N/A') . ' ' . $timeRange;
                            })
                            ->implode(' | ');
                        $scheduleSummary = ($event->start_date?->format('M d, Y') ?? 'N/A')
                            . (($event->end_date && $event->start_date && $event->end_date->ne($event->start_date)) ? ' - ' . $event->end_date->format('M d, Y') : '');
                        $budgetCost = isset($budget['budget_cost']) && $budget['budget_cost'] !== null && $budget['budget_cost'] !== ''
                            ? 'PHP ' . number_format((float) $budget['budget_cost'], 2)
                            : 'N/A';
                    @endphp
                    <tr>
                        <td>
                            <p class="font-medium text-(--color-text-primary)">{{ $event->title }}</p>
                            <p class="text-xs text-(--color-text-tertiary)">
                                {{ ucfirst($event->proposal_category ?? 'activity') }} &middot; {{ $event->eventDays->count() }} day(s)
                            </p>
                            @if($event->parentEvent)
                                <p class="text-xs text-(--color-text-tertiary)">Parent: {{ Str::limit($event->parentEvent->title, 32) }}</p>
                            @endif
                        </td>
                        <td>
                            <span class="text-sm text-(--color-text-secondary)">{{ $event->organization?->acronym ?? 'N/A' }}</span>
                        </td>
                        <td>
                            <span class="text-sm font-data text-(--color-text-secondary)">
                                {{ $event->start_date?->format('M d') }}
                                @if($event->end_date && $event->end_date->ne($event->start_date))
                                    – {{ $event->end_date->format('M d, Y') }}
                                @else
                                    , {{ $event->start_date?->format('Y') }}
                                @endif
                            </span>
                        </td>
                        <td>
                            <div class="space-y-1 text-xs">
                                @if($event->proposal_document_path)
                                    <a href="{{ route('dashboard.events.proposal-document', $event->id) }}" class="inline-flex items-center gap-1 text-(--color-accent) hover:underline">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414A1 1 0 0119 9.414V19a2 2 0 01-2 2z"/></svg>
                                        Softcopy
                                    </a>
                                @else
                                    <span class="text-(--color-destructive)">Missing softcopy</span>
                                @endif

                                <div class="{{ $event->hardcopy_submitted ? 'text-(--color-success)' : 'text-(--color-warning)' }}">
                                    {{ $event->hardcopy_submitted ? 'Hardcopy submitted' : 'Hardcopy pending' }}
                                </div>

                                <div class="text-(--color-text-tertiary)">
                                    Head {{ $event->head_organization_signed ? 'signed' : 'pending' }},
                                    Adviser {{ $event->adviser_signed ? 'signed' : 'pending' }}
                                </div>
                            </div>
                        </td>
                        <td>
                            <span class="badge badge-{{ str_replace('_', '-', $event->status) }}">
                                {{ ucwords(str_replace('_', ' ', $event->status)) }}
                            </span>
                        </td>
                        <td>
                            <button type="button"
                                    class="btn btn-sm btn-secondary event-export-button"
                                    aria-haspopup="dialog"
                                    aria-controls="export-modal"
                                    title="Open export options"
                                    data-export-trigger
                                    data-export-title="{{ $event->title }}"
                                    data-attendance-pdf="{{ route('dashboard.events.exports.attendance.pdf', $event->id) }}"
                                    data-attendance-excel="{{ route('dashboard.events.exports.attendance.excel', $event->id) }}"
                                    data-evaluations-pdf="{{ route('dashboard.events.exports.evaluations.pdf', $event->id) }}"
                                    data-evaluations-excel="{{ route('dashboard.events.exports.evaluations.excel', $event->id) }}">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v12m0 0l4-4m-4 4l-4-4M5 21h14"/></svg>
                                Export
                            </button>
                        </td>
                        <td class="text-right">
                            <div class="flex items-center justify-end gap-1">
                                @if($user->hasRole('Super Admin (OSA)'))
                                <button type="button"
                                        class="btn btn-sm btn-secondary"
                                        aria-haspopup="dialog"
                                        aria-controls="proposal-detail-modal"
                                        title="View proposal details"
                                        data-detail-trigger
                                        data-detail-title="{{ $event->title }}"
                                        data-detail-organizer="{{ trim(($event->organization?->acronym ? $event->organization->acronym . ' - ' : '') . ($event->organization?->name ?? 'N/A')) }}"
                                        data-detail-status="{{ ucwords(str_replace('_', ' ', $event->status)) }}"
                                        data-detail-category="{{ ucfirst($event->proposal_category ?? 'activity') }}"
                                        data-detail-parent="{{ $event->parentEvent ? trim(($event->parentEvent->organization?->acronym ? $event->parentEvent->organization->acronym . ' - ' : '') . $event->parentEvent->title) : 'None' }}"
                                        data-detail-schedule="{{ $scheduleSummary }}"
                                        data-detail-days="{{ $eventDaysSummary ?: 'No event days recorded' }}"
                                        data-detail-location-type="{{ $event->location_type ? ucwords(str_replace('-', ' ', $event->location_type)) : 'N/A' }}"
                                        data-detail-location-details="{{ $event->location_details ?: 'N/A' }}"
                                        data-detail-implementing-office="{{ $target['implementing_office'] ?? 'N/A' }}"
                                        data-detail-collaborating-office="{{ $target['collaborating_office'] ?? 'N/A' }}"
                                        data-detail-target-participants="{{ $target['target_participants'] ?? 'N/A' }}"
                                        data-detail-source-of-fund="{{ $budget['source_of_fund'] ?? 'N/A' }}"
                                        data-detail-budget-cost="{{ $budgetCost }}"
                                        data-detail-resolution-number="{{ $event->resolution_number ?: 'N/A' }}"
                                        data-detail-softcopy-url="{{ $event->proposal_document_path ? route('dashboard.events.proposal-document', $event->id) : '' }}"
                                        data-detail-softcopy-name="{{ $event->proposal_document_original_name ?: 'Not attached' }}"
                                        data-detail-hardcopy="{{ $event->hardcopy_submitted ? 'Submitted' : 'Pending' }}"
                                        data-detail-head-signed="{{ $event->head_organization_signed ? 'Signed' : 'Pending' }}"
                                        data-detail-adviser-signed="{{ $event->adviser_signed ? 'Signed' : 'Pending' }}">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M2.25 12s3.75-6.75 9.75-6.75S21.75 12 21.75 12 18 18.75 12 18.75 2.25 12 2.25 12z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                    View
                                </button>
                                @endif

                                {{-- Status-dependent actions --}}
                                @if($canCreateProposals && $event->status === 'draft')
                                @if(!$event->hardcopy_submitted)
                                <form method="POST" action="{{ route('dashboard.events.hardcopy', $event->id) }}" class="inline">
                                    @csrf
                                    <input type="hidden" name="head_organization_signed" value="1">
                                    <input type="hidden" name="adviser_signed" value="1">
                                    <button type="submit" class="btn btn-sm btn-secondary" title="Record hardcopy submission">Hardcopy</button>
                                </form>
                                @endif
                                <form method="POST" action="{{ route('dashboard.events.submit', $event->id) }}" class="inline">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-primary" title="Submit for Review">Submit</button>
                                </form>
                                @endif

                                @if($user->hasRole('Super Admin (OSA)'))
                                    @if($event->status === 'submitted')
                                    <form method="POST" action="{{ route('dashboard.events.review', $event->id) }}" class="inline">
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-secondary" title="Start Review">Review</button>
                                    </form>
                                    @endif

                                    @if(in_array($event->status, ['submitted', 'under_review']))
                                    <form method="POST" action="{{ route('dashboard.events.approve', $event->id) }}" class="inline">
                                        @csrf
                                        <button type="submit" class="btn btn-sm" style="background-color: var(--color-success); color: white;" title="Approve">Approve</button>
                                    </form>
                                    <form method="POST" action="{{ route('dashboard.events.reject', $event->id) }}" class="inline">
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-destructive" title="Reject">Reject</button>
                                    </form>
                                    @endif
                                @endif

                                @if(in_array($event->status, ['approved', 'completed']))
                                <button type="button" @click="$dispatch('open-analytics', { id: '{{ $event->id }}', title: '{{ addslashes($event->title) }}' })" class="btn btn-sm btn-secondary" title="View Real-Time Analytics" style="background-color: var(--color-accent-subtle); color: var(--color-accent); border: 1px solid var(--color-accent-light);">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                                    Analytics
                                </button>
                                <form method="POST" action="{{ route('dashboard.events.scanner-link', $event->id) }}" class="inline">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-secondary" title="Generate Scanner Link">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101"/></svg>
                                        Link
                                    </button>
                                </form>
                                @endif

                                @if(($event->unprocessed_comment_count ?? 0) > 0)
                                <form method="POST" action="{{ route('dashboard.events.sentiments.analyze', $event->id) }}" class="inline">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-secondary" title="Queue sentiment analysis">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 3.75L8.25 7.5 4.5 9l3.75 1.5 1.5 3.75 1.5-3.75L15 9l-3.75-1.5-1.5-3.75z"/></svg>
                                        AI
                                    </button>
                                </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="7">
                            <div class="empty-state py-8">
                                <svg class="empty-state-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                                <p class="empty-state-title">No events found</p>
                                <p class="empty-state-description">Create your first event proposal to get started.</p>
                            </div>
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($events->hasPages())
        <div class="card-footer">
            {{ $events->withQueryString()->links() }}
        </div>
        @endif
    </div>

    @if($canCreateProposals)
    {{-- Create Event Modal (HTML dialog) --}}
    <dialog id="create-event-modal"
            class="proposal-modal"
            aria-labelledby="create-event-modal-title"
            aria-describedby="create-event-modal-description"
            onclick="if (event.target === this) this.close()">
        <form method="POST" action="{{ route('dashboard.events.create') }}" enctype="multipart/form-data" x-data="eventDaysForm(@js(old('event_days', [])))" class="proposal-modal-form" onclick="event.stopPropagation()">
            @csrf
            <div class="proposal-modal-header">
                <div class="proposal-modal-title-row">
                    <span class="proposal-modal-icon" aria-hidden="true">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414A1 1 0 0119 9.414V19a2 2 0 01-2 2z"/></svg>
                    </span>
                    <div>
                        <p class="proposal-modal-kicker">PPA proposal packet</p>
                        <h2 id="create-event-modal-title" class="proposal-modal-title">New Event Proposal</h2>
                        <p id="create-event-modal-description" class="proposal-modal-subtitle">Submit the digital record with the official softcopy and OSA hardcopy status.</p>
                    </div>
                </div>
                <button type="button" onclick="document.getElementById('create-event-modal').close()" class="btn-icon btn-ghost" aria-label="Close proposal modal">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>

            <div class="proposal-modal-body">
                @if($errors->any())
                    <div class="proposal-error-summary" role="alert" aria-live="assertive">
                        <p>Review the proposal fields and try again.</p>
                        <ul>
                            @foreach($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <fieldset class="proposal-modal-section">
                    <legend class="proposal-section-title">
                        <span class="proposal-section-step">1</span>
                        Proposal identity
                    </legend>

                    <div class="proposal-grid">
                        @if($user->hasRole('Super Admin (OSA)'))
                        <div class="proposal-grid-full">
                            <label for="organization_id" class="form-label">Organizer <span class="text-(--color-destructive)">*</span></label>
                            <select name="organization_id" id="organization_id" class="form-input form-select @error('organization_id') form-input-error @enderror" required>
                                <option value="">Select organizer</option>
                                @foreach($organizations as $org)
                                    <option value="{{ $org->id }}" {{ old('organization_id') === $org->id ? 'selected' : '' }}>
                                        {{ $org->acronym }} — {{ $org->name }}
                                    </option>
                                @endforeach
                            </select>
                            @error('organization_id') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                        </div>
                        @endif

                        <div class="proposal-grid-full">
                            <label for="title" class="form-label">Event Title <span class="text-(--color-destructive)">*</span></label>
                            <input type="text" name="title" id="title" class="form-input @error('title') form-input-error @enderror" required maxlength="255"
                                   placeholder="e.g. PSITS General Assembly 2026" value="{{ old('title') }}">
                            @error('title') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                        </div>

                        <div>
                        <label for="proposal_category" class="form-label">PPA Category <span class="text-(--color-destructive)">*</span></label>
                        <select name="proposal_category" id="proposal_category" class="form-input form-select @error('proposal_category') form-input-error @enderror" required>
                            <option value="">Select category</option>
                            @foreach(['program' => 'Program', 'project' => 'Project', 'activity' => 'Activity'] as $value => $label)
                                <option value="{{ $value }}" {{ old('proposal_category') === $value ? 'selected' : '' }}>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('proposal_category') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="parent_event_id" class="form-label">Parent Permit</label>
                            <select name="parent_event_id" id="parent_event_id" class="form-input form-select @error('parent_event_id') form-input-error @enderror">
                                <option value="">None</option>
                                @foreach($parentEvents as $parentEvent)
                                    <option value="{{ $parentEvent->id }}" {{ old('parent_event_id') === $parentEvent->id ? 'selected' : '' }}>
                                        {{ $parentEvent->organization?->acronym }} — {{ Str::limit($parentEvent->title, 48) }}
                                    </option>
                                @endforeach
                            </select>
                            <p class="form-helper">For sub-events under USG, LSG, or ARO permits.</p>
                            @error('parent_event_id') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                        </div>
                    </div>
                </fieldset>

                <fieldset class="proposal-modal-section">
                    <legend class="proposal-section-title">
                        <span class="proposal-section-step">2</span>
                        Schedule and venue
                    </legend>

                    <div class="proposal-grid">
                        <div>
                            <label for="start_date" class="form-label">Start Date <span class="text-(--color-destructive)">*</span></label>
                            <input type="date" name="start_date" id="start_date" class="form-input @error('start_date') form-input-error @enderror" required value="{{ old('start_date') }}">
                            @error('start_date') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="end_date" class="form-label">End Date <span class="text-(--color-destructive)">*</span></label>
                            <input type="date" name="end_date" id="end_date" class="form-input @error('end_date') form-input-error @enderror" required value="{{ old('end_date') }}">
                            @error('end_date') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="location_type" class="form-label">Location Type <span class="text-(--color-destructive)">*</span></label>
                            <select name="location_type" id="location_type" class="form-input form-select @error('location_type') form-input-error @enderror" required>
                                <option value="on-campus" {{ old('location_type') === 'on-campus' ? 'selected' : '' }}>On-Campus</option>
                                <option value="off-campus" {{ old('location_type') === 'off-campus' ? 'selected' : '' }}>Off-Campus</option>
                            </select>
                            @error('location_type') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="location_details" class="form-label">Location Details</label>
                            <input type="text" name="location_details" id="location_details" class="form-input @error('location_details') form-input-error @enderror"
                                   placeholder="e.g. USM Gymnasium" value="{{ old('location_details') }}">
                            @error('location_details') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                        </div>
                    </div>
                </fieldset>

                <fieldset class="proposal-modal-section">
                    <legend class="proposal-section-title">
                        <span class="proposal-section-step">3</span>
                        Offices, participants, and budget
                    </legend>

                    <div class="proposal-grid">
                        <div>
                            <label for="implementing_office" class="form-label">Implementing Office/Organization</label>
                            <input type="text" name="implementing_office" id="implementing_office" class="form-input @error('implementing_office') form-input-error @enderror"
                                   value="{{ old('implementing_office') }}" placeholder="e.g. PSITS">
                            @error('implementing_office') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="collaborating_office" class="form-label">Collaborating Office/Organization</label>
                            <input type="text" name="collaborating_office" id="collaborating_office" class="form-input @error('collaborating_office') form-input-error @enderror"
                                   value="{{ old('collaborating_office') }}" placeholder="Optional partner office">
                            @error('collaborating_office') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                        </div>

                        <div class="proposal-grid-full">
                            <label for="target_participants" class="form-label">Target Beneficiaries / Participants</label>
                            <textarea name="target_participants" id="target_participants" rows="3" class="form-input @error('target_participants') form-input-error @enderror"
                                      placeholder="Who will attend, approximate count, and expected benefit">{{ old('target_participants') }}</textarea>
                            @error('target_participants') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label for="source_of_fund" class="form-label">Source of Fund</label>
                            <input type="text" name="source_of_fund" id="source_of_fund" class="form-input @error('source_of_fund') form-input-error @enderror"
                                   value="{{ old('source_of_fund') }}" placeholder="e.g. Organization fund">
                            @error('source_of_fund') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label for="budget_cost" class="form-label">Budget / Cost (PHP)</label>
                            <input type="number" min="0" step="0.01" name="budget_cost" id="budget_cost" class="form-input @error('budget_cost') form-input-error @enderror"
                                   value="{{ old('budget_cost') }}" placeholder="0.00">
                            @error('budget_cost') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                        </div>
                    </div>
                </fieldset>

                <fieldset class="proposal-modal-section">
                    <legend class="proposal-section-title">
                        <span class="proposal-section-step">4</span>
                        Official PPA packet
                    </legend>

                    <div class="proposal-grid">
                        <div class="proposal-grid-full">
                            <div class="proposal-file-box">
                                <span class="proposal-file-icon" aria-hidden="true">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M12 16v-8m0 0l-3 3m3-3l3 3M4 16.5V18a2 2 0 002 2h12a2 2 0 002-2v-1.5"/></svg>
                                </span>
                                <div>
                                    <label for="proposal_document" class="form-label">Official PPA Proposal Softcopy <span class="text-(--color-destructive)">*</span></label>
                                    <input type="file" name="proposal_document" id="proposal_document"
                                           class="form-input @error('proposal_document') form-input-error @enderror"
                                           accept=".pdf,.doc,.docx,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document" required>
                                    <p class="form-helper">Accepted: PDF, DOC, DOCX up to 10 MB.</p>
                                    @error('proposal_document') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                                </div>
                            </div>
                        </div>

                        <div class="proposal-grid-full">
                            <label for="resolution_number" class="form-label">Resolution Number</label>
                            <input type="text" name="resolution_number" id="resolution_number" class="form-input @error('resolution_number') form-input-error @enderror"
                                   value="{{ old('resolution_number') }}" placeholder="For USG/LSG proposals when applicable">
                            @error('resolution_number') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                        </div>
                    </div>
                </fieldset>

                <fieldset class="proposal-modal-section">
                    <legend class="proposal-section-title">
                        <span class="proposal-section-step">5</span>
                        OSA hardcopy status
                    </legend>

                    <div class="proposal-check-grid">
                        <label class="proposal-check-item">
                            <input type="checkbox" name="hardcopy_submitted" value="1" {{ old('hardcopy_submitted') ? 'checked' : '' }}>
                            <span>
                                <span class="proposal-check-title">Hardcopy submitted</span>
                                <span class="proposal-check-note">Physical PPA packet received by OSA.</span>
                            </span>
                        </label>
                        <label class="proposal-check-item">
                            <input type="checkbox" name="head_organization_signed" value="1" {{ old('head_organization_signed') ? 'checked' : '' }}>
                            <span>
                                <span class="proposal-check-title">Head signed</span>
                                <span class="proposal-check-note">Organization head signature is complete.</span>
                            </span>
                        </label>
                        <label class="proposal-check-item">
                            <input type="checkbox" name="adviser_signed" value="1" {{ old('adviser_signed') ? 'checked' : '' }}>
                            <span>
                                <span class="proposal-check-title">Adviser signed</span>
                                <span class="proposal-check-note">Adviser signature is complete.</span>
                            </span>
                        </label>
                    </div>
                </fieldset>

                {{-- Dynamic Event Days --}}
                <fieldset class="proposal-modal-section">
                    <legend class="proposal-section-title">
                        <span class="proposal-section-step">6</span>
                        Event days
                    </legend>
                    <div class="proposal-days-header">
                        <p class="form-helper" style="margin-top: 0;">Add each day that scanners will record attendance for.</p>
                        <button type="button" @click="addDay()" class="btn btn-sm btn-ghost">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                            Add Day
                        </button>
                    </div>
                    <template x-for="(day, index) in days" :key="index">
                        <div class="proposal-day-row">
                            <span class="proposal-day-index" x-text="'D' + day.day_number"></span>
                            <div>
                                <label class="proposal-mini-label">Date</label>
                                <input type="date" :name="'event_days['+index+'][date]'" x-model="day.date" class="form-input text-sm" required>
                            </div>
                            <div>
                                <label class="proposal-mini-label">Start</label>
                                <input type="time" :name="'event_days['+index+'][start_time]'" x-model="day.start_time" class="form-input text-sm" required>
                            </div>
                            <div>
                                <label class="proposal-mini-label">End</label>
                                <input type="time" :name="'event_days['+index+'][end_time]'" x-model="day.end_time" class="form-input text-sm" required>
                            </div>
                            <button type="button" @click="removeDay(index)" class="btn-icon btn-ghost text-(--color-destructive)" x-show="days.length > 1" aria-label="Remove day">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                            </button>
                        </div>
                    </template>
                    @error('event_days') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                </fieldset>
            </div>

            <div class="proposal-modal-footer">
                <p class="proposal-required-note"><span class="text-(--color-destructive)">*</span> Required before saving the draft.</p>
                <div class="proposal-footer-actions">
                    <button type="button" onclick="document.getElementById('create-event-modal').close()" class="btn btn-secondary">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M5 13l4 4L19 7"/></svg>
                        Create Proposal
                    </button>
                </div>
            </div>
        </form>
    </dialog>
    @endif

    @if($user->hasRole('Super Admin (OSA)'))
    {{-- Proposal Review Details Modal --}}
    <dialog id="proposal-detail-modal"
            class="proposal-modal modal-md"
            aria-labelledby="proposal-detail-modal-title">
        <div class="proposal-modal-form" onclick="event.stopPropagation()">
            <div class="proposal-modal-header">
                <div class="proposal-modal-title-row">
                    <span class="proposal-modal-icon" aria-hidden="true">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M9 12h6m-6 4h6M7 3h6l4 4v14H7zM13 3v5h5"/></svg>
                    </span>
                    <div>
                        <p id="proposal-detail-modal-event-title" class="proposal-modal-kicker">Event proposal</p>
                        <h2 id="proposal-detail-modal-title" class="proposal-modal-title">Proposal Details</h2>
                        <p class="proposal-modal-subtitle">Review the submitted PPA metadata before taking OSA action.</p>
                    </div>
                </div>
                <button type="button" class="btn-icon btn-ghost proposal-detail-close" aria-label="Close proposal details">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>

            <div class="proposal-modal-body">
                <section class="proposal-modal-section">
                    <h3 class="proposal-detail-heading">Identity</h3>
                    <div class="proposal-detail-grid">
                        <div class="proposal-detail-item">
                            <span class="proposal-detail-label">Status</span>
                            <span id="proposal-detail-status" class="proposal-detail-value"></span>
                        </div>
                        <div class="proposal-detail-item">
                            <span class="proposal-detail-label">Organizer</span>
                            <span id="proposal-detail-organizer" class="proposal-detail-value"></span>
                        </div>
                        <div class="proposal-detail-item">
                            <span class="proposal-detail-label">PPA category</span>
                            <span id="proposal-detail-category" class="proposal-detail-value"></span>
                        </div>
                        <div class="proposal-detail-item">
                            <span class="proposal-detail-label">Parent permit</span>
                            <span id="proposal-detail-parent" class="proposal-detail-value"></span>
                        </div>
                    </div>
                </section>

                <section class="proposal-modal-section">
                    <h3 class="proposal-detail-heading">Schedule and venue</h3>
                    <div class="proposal-detail-grid">
                        <div class="proposal-detail-item">
                            <span class="proposal-detail-label">Schedule</span>
                            <span id="proposal-detail-schedule" class="proposal-detail-value"></span>
                        </div>
                        <div class="proposal-detail-item">
                            <span class="proposal-detail-label">Location type</span>
                            <span id="proposal-detail-location-type" class="proposal-detail-value"></span>
                        </div>
                        <div class="proposal-detail-item proposal-detail-wide">
                            <span class="proposal-detail-label">Location details</span>
                            <span id="proposal-detail-location-details" class="proposal-detail-value"></span>
                        </div>
                        <div class="proposal-detail-item proposal-detail-wide">
                            <span class="proposal-detail-label">Event days</span>
                            <span id="proposal-detail-days" class="proposal-detail-value"></span>
                        </div>
                    </div>
                </section>

                <section class="proposal-modal-section">
                    <h3 class="proposal-detail-heading">Offices, participants, and budget</h3>
                    <div class="proposal-detail-grid">
                        <div class="proposal-detail-item">
                            <span class="proposal-detail-label">Implementing office</span>
                            <span id="proposal-detail-implementing-office" class="proposal-detail-value"></span>
                        </div>
                        <div class="proposal-detail-item">
                            <span class="proposal-detail-label">Collaborating office</span>
                            <span id="proposal-detail-collaborating-office" class="proposal-detail-value"></span>
                        </div>
                        <div class="proposal-detail-item">
                            <span class="proposal-detail-label">Source of fund</span>
                            <span id="proposal-detail-source-of-fund" class="proposal-detail-value"></span>
                        </div>
                        <div class="proposal-detail-item">
                            <span class="proposal-detail-label">Budget / cost</span>
                            <span id="proposal-detail-budget-cost" class="proposal-detail-value"></span>
                        </div>
                        <div class="proposal-detail-item proposal-detail-wide">
                            <span class="proposal-detail-label">Target beneficiaries / participants</span>
                            <span id="proposal-detail-target-participants" class="proposal-detail-value"></span>
                        </div>
                    </div>
                </section>

                <section class="proposal-modal-section">
                    <h3 class="proposal-detail-heading">Official PPA packet</h3>
                    <div class="proposal-detail-grid">
                        <div class="proposal-detail-item">
                            <span class="proposal-detail-label">Resolution number</span>
                            <span id="proposal-detail-resolution-number" class="proposal-detail-value"></span>
                        </div>
                        <div class="proposal-detail-item">
                            <span class="proposal-detail-label">Proposal softcopy</span>
                            <span class="proposal-detail-value">
                                <a id="proposal-detail-softcopy-link" href="#" class="text-(--color-accent) hover:underline">Download softcopy</a>
                                <span id="proposal-detail-softcopy-missing">Not attached</span>
                            </span>
                        </div>
                        <div class="proposal-detail-item">
                            <span class="proposal-detail-label">Hardcopy</span>
                            <span id="proposal-detail-hardcopy" class="proposal-detail-value"></span>
                        </div>
                        <div class="proposal-detail-item">
                            <span class="proposal-detail-label">Signatures</span>
                            <span class="proposal-detail-value">
                                Head <span id="proposal-detail-head-signed"></span>,
                                Adviser <span id="proposal-detail-adviser-signed"></span>
                            </span>
                        </div>
                    </div>
                </section>
            </div>

            <div class="proposal-modal-footer">
                <p class="proposal-required-note">OSA review metadata</p>
                <div class="proposal-footer-actions">
                    <button type="button" class="btn btn-secondary proposal-detail-close">Close</button>
                </div>
            </div>
        </div>
    </dialog>
    @endif

    {{-- Export Options Modal --}}
    <dialog id="export-modal"
            class="proposal-modal modal-sm"
            aria-labelledby="export-modal-title">
        <div class="proposal-modal-form" onclick="event.stopPropagation()">
            <div class="proposal-modal-header">
                <div class="proposal-modal-title-row">
                    <span class="proposal-modal-icon" aria-hidden="true">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M12 3v12m0 0l4-4m-4 4l-4-4M5 21h14"/></svg>
                    </span>
                    <div>
                        <p id="export-modal-event-title" class="proposal-modal-kicker">Event proposal</p>
                        <h2 id="export-modal-title" class="proposal-modal-title">Export Event</h2>
                    </div>
                </div>
                <button type="button" class="btn-icon btn-ghost export-modal-close" aria-label="Close export modal">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>

            <div class="proposal-modal-body">
                <div class="export-option-grid">
                    <section class="export-option-section" aria-labelledby="attendance-export-title">
                        <h3 id="attendance-export-title" class="export-option-heading">Attendance</h3>
                        <div class="export-option-actions">
                            <a id="export-modal-attendance-pdf" class="export-option-card export-option-pdf" href="#">
                                <span class="export-option-icon" aria-hidden="true">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M7 3h7l5 5v13H7zM14 3v5h5"/></svg>
                                </span>
                                <span>
                                    <span class="export-option-type">PDF</span>
                                    <span class="export-option-label">Attendance report</span>
                                </span>
                            </a>
                            <a id="export-modal-attendance-excel" class="export-option-card export-option-xlsx" href="#">
                                <span class="export-option-icon" aria-hidden="true">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 5h16v14H4zM4 10h16M9 5v14"/></svg>
                                </span>
                                <span>
                                    <span class="export-option-type">Excel</span>
                                    <span class="export-option-label">Attendance report</span>
                                </span>
                            </a>
                        </div>
                    </section>

                    <section class="export-option-section" aria-labelledby="evaluation-export-title">
                        <h3 id="evaluation-export-title" class="export-option-heading">Evaluation</h3>
                        <div class="export-option-actions">
                            <a id="export-modal-evaluations-pdf" class="export-option-card export-option-pdf" href="#">
                                <span class="export-option-icon" aria-hidden="true">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M7 3h7l5 5v13H7zM14 3v5h5"/></svg>
                                </span>
                                <span>
                                    <span class="export-option-type">PDF</span>
                                    <span class="export-option-label">Evaluation report</span>
                                </span>
                            </a>
                            <a id="export-modal-evaluations-excel" class="export-option-card export-option-xlsx" href="#">
                                <span class="export-option-icon" aria-hidden="true">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 5h16v14H4zM4 10h16M9 5v14"/></svg>
                                </span>
                                <span>
                                    <span class="export-option-type">Excel</span>
                                    <span class="export-option-label">Evaluation report</span>
                                </span>
                            </a>
                        </div>
                    </section>
                </div>
            </div>

            <div class="proposal-modal-footer">
                <p class="proposal-required-note">PDF and Excel outputs</p>
                <div class="proposal-footer-actions">
                    <button type="button" class="btn btn-secondary export-modal-close">Close</button>
                </div>
            </div>
        </div>
    </dialog>

    {{-- Real-Time Analytics Modal --}}
    <dialog id="analytics-modal"
            class="proposal-modal modal-md"
            aria-labelledby="analytics-modal-title"
            aria-describedby="analytics-modal-description"
            x-data="analyticsModal"
            @open-analytics.window="openModal($event.detail)"
            onclick="if (event.target === this) closeModal()">
        <div class="proposal-modal-form" onclick="event.stopPropagation()">
            <div class="proposal-modal-header">
                <div class="proposal-modal-title-row">
                    <span class="proposal-modal-icon" aria-hidden="true">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                    </span>
                    <div>
                        <p class="proposal-modal-kicker" x-text="eventTitle || 'Event Analytics'"></p>
                        <h2 id="analytics-modal-title" class="proposal-modal-title">Real-Time Event Analytics</h2>
                        <p id="analytics-modal-description" class="proposal-modal-subtitle">Aggregated evaluation rates, sentiment scores, and participant demographics.</p>
                    </div>
                </div>
                <button type="button" @click="closeModal()" class="btn-icon btn-ghost" aria-label="Close analytics modal">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>

            <div class="proposal-modal-body">
                {{-- Loading State --}}
                <div x-show="loading" class="py-12 flex flex-col items-center justify-center gap-3">
                    <div class="w-8 h-8 rounded-full border-4 border-(--color-accent-light) border-t-(--color-accent) animate-spin"></div>
                    <p class="text-sm text-(--color-text-secondary)">Retrieving real-time analytics...</p>
                </div>

                {{-- Content State --}}
                <div x-show="!loading" class="space-y-4">
                    {{-- Grid 1: Attendance & Evaluations Summary --}}
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        {{-- Attendance Card --}}
                        <div class="proposal-modal-section">
                            <h3 class="font-semibold text-sm text-(--color-text-primary) mb-3 flex items-center gap-2">
                                <svg class="w-4 h-4 text-(--color-accent)" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                                Attendance Rate
                            </h3>
                            <div class="flex items-baseline gap-2 mb-1">
                                <span class="text-2xl font-bold text-(--color-text-primary)" x-text="(attendance.attendance_rate || 0) + '%'"></span>
                                <span class="text-xs text-(--color-text-tertiary)" x-text="'(' + (attendance.attended || 0) + ' / ' + (attendance.total_demographic || 0) + ' students)'"></span>
                            </div>
                            <div class="w-full bg-(--color-surface-raised) h-2 rounded-full overflow-hidden">
                                <div class="bg-(--color-accent) h-full transition-all duration-500" :style="'width: ' + (attendance.attendance_rate || 0) + '%'"></div>
                            </div>
                        </div>

                        {{-- Evaluation Rating Card --}}
                        <div class="proposal-modal-section">
                            <h3 class="font-semibold text-sm text-(--color-text-primary) mb-3 flex items-center gap-2">
                                <svg class="w-4 h-4 text-amber-500" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
                                Evaluation Rating
                            </h3>
                            <div class="flex items-baseline gap-2">
                                <span class="text-2xl font-bold text-(--color-text-primary)" x-text="(evaluations.average_rating || '0.00')"></span>
                                <span class="text-xs text-(--color-text-tertiary)">/ 5.00 average</span>
                            </div>
                            <p class="text-xs text-(--color-text-tertiary) mt-1" x-text="'Total evaluations: ' + (evaluations.total_submitted || 0)"></p>
                        </div>
                    </div>

                    {{-- Grid 2: Sentiments --}}
                    <div class="proposal-modal-section">
                        <h3 class="font-semibold text-sm text-(--color-text-primary) mb-3 flex items-center gap-2">
                            <svg class="w-4 h-4 text-(--color-accent)" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"/></svg>
                            Comment Sentiments ({{ config('services.ai.provider') === 'ollama' ? 'Ollama' : 'Gemini' }} NLP Classifier)
                        </h3>
                        <div class="space-y-2">
                            {{-- Positive Sentiment --}}
                            <div>
                                <div class="flex justify-between text-xs text-(--color-text-secondary) mb-1">
                                    <span class="font-medium text-emerald-600">Positive Comments</span>
                                    <span x-text="evaluations.sentiments.positive + ' comment(s)'"></span>
                                </div>
                                <div class="w-full bg-(--color-surface-raised) h-2 rounded-full overflow-hidden">
                                    <div class="bg-emerald-500 h-full transition-all duration-500" :style="'width: ' + getPercentage(evaluations.sentiments.positive, evaluations.total_submitted) + '%'"></div>
                                </div>
                            </div>
                            {{-- Neutral Sentiment --}}
                            <div>
                                <div class="flex justify-between text-xs text-(--color-text-secondary) mb-1">
                                    <span class="font-medium text-slate-500">Neutral Comments</span>
                                    <span x-text="evaluations.sentiments.neutral + ' comment(s)'"></span>
                                </div>
                                <div class="w-full bg-(--color-surface-raised) h-2 rounded-full overflow-hidden">
                                    <div class="bg-slate-400 h-full transition-all duration-500" :style="'width: ' + getPercentage(evaluations.sentiments.neutral, evaluations.total_submitted) + '%'"></div>
                                </div>
                            </div>
                            {{-- Negative Sentiment --}}
                            <div>
                                <div class="flex justify-between text-xs text-(--color-text-secondary) mb-1">
                                    <span class="font-medium text-rose-600">Negative Comments</span>
                                    <span x-text="evaluations.sentiments.negative + ' comment(s)'"></span>
                                </div>
                                <div class="w-full bg-(--color-surface-raised) h-2 rounded-full overflow-hidden">
                                    <div class="bg-rose-500 h-full transition-all duration-500" :style="'width: ' + getPercentage(evaluations.sentiments.negative, evaluations.total_submitted) + '%'"></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- Section 3: Demographic Aggregations --}}
                    <div class="proposal-modal-section">
                        <h3 class="font-semibold text-sm text-(--color-text-primary) mb-3 flex items-center gap-2">
                            <svg class="w-4 h-4 text-(--color-accent)" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
                            Participant Demographic Breakdown
                        </h3>
                        <div class="flex flex-wrap gap-2">
                            <template x-for="demo in demographics" :key="demo.program_code">
                                <div class="px-3 py-1.5 rounded-lg border border-(--color-border) bg-(--color-surface-raised) flex items-center gap-2 text-xs">
                                    <span class="font-bold text-(--color-accent)" x-text="demo.program_code || 'N/A'"></span>
                                    <span class="w-px h-3 bg-white/20"></span>
                                    <span class="text-(--color-text-secondary)" x-text="demo.count + ' student(s)'"></span>
                                </div>
                            </template>
                            <div x-show="demographics.length === 0" class="text-xs text-(--color-text-tertiary) italic">
                                No demographic details available yet.
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="proposal-modal-footer">
                <p class="proposal-required-note">Real-time attendance & feedback metrics</p>
                <div class="proposal-footer-actions">
                    <button type="button" @click="closeModal()" class="btn btn-secondary">Close</button>
                </div>
            </div>
        </div>
    </dialog>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const modal = document.getElementById('export-modal');
            if (!modal) return;

            const title = document.getElementById('export-modal-event-title');
            const firstLink = document.getElementById('export-modal-attendance-pdf');
            const linkTargets = {
                attendancePdf: firstLink,
                attendanceExcel: document.getElementById('export-modal-attendance-excel'),
                evaluationsPdf: document.getElementById('export-modal-evaluations-pdf'),
                evaluationsExcel: document.getElementById('export-modal-evaluations-excel'),
            };

            const closeModal = () => {
                if (modal.open) {
                    modal.close();
                }
            };

            document.querySelectorAll('[data-export-trigger]').forEach((button) => {
                button.addEventListener('click', () => {
                    const data = button.dataset;
                    title.textContent = data.exportTitle || 'Event proposal';
                    linkTargets.attendancePdf.href = data.attendancePdf || '#';
                    linkTargets.attendanceExcel.href = data.attendanceExcel || '#';
                    linkTargets.evaluationsPdf.href = data.evaluationsPdf || '#';
                    linkTargets.evaluationsExcel.href = data.evaluationsExcel || '#';

                    if (modal.showModal && !modal.open) {
                        modal.showModal();
                    }
                    firstLink?.focus();
                });
            });

            modal.addEventListener('click', (event) => {
                if (event.target === modal) {
                    closeModal();
                }
            });

            document.querySelectorAll('.export-modal-close').forEach((button) => {
                button.addEventListener('click', closeModal);
            });

            const detailModal = document.getElementById('proposal-detail-modal');
            if (detailModal) {
                const detailTitle = document.getElementById('proposal-detail-modal-event-title');
                const softcopyLink = document.getElementById('proposal-detail-softcopy-link');
                const softcopyMissing = document.getElementById('proposal-detail-softcopy-missing');
                const detailFields = {
                    status: document.getElementById('proposal-detail-status'),
                    organizer: document.getElementById('proposal-detail-organizer'),
                    category: document.getElementById('proposal-detail-category'),
                    parent: document.getElementById('proposal-detail-parent'),
                    schedule: document.getElementById('proposal-detail-schedule'),
                    days: document.getElementById('proposal-detail-days'),
                    locationType: document.getElementById('proposal-detail-location-type'),
                    locationDetails: document.getElementById('proposal-detail-location-details'),
                    implementingOffice: document.getElementById('proposal-detail-implementing-office'),
                    collaboratingOffice: document.getElementById('proposal-detail-collaborating-office'),
                    targetParticipants: document.getElementById('proposal-detail-target-participants'),
                    sourceOfFund: document.getElementById('proposal-detail-source-of-fund'),
                    budgetCost: document.getElementById('proposal-detail-budget-cost'),
                    resolutionNumber: document.getElementById('proposal-detail-resolution-number'),
                    hardcopy: document.getElementById('proposal-detail-hardcopy'),
                    headSigned: document.getElementById('proposal-detail-head-signed'),
                    adviserSigned: document.getElementById('proposal-detail-adviser-signed'),
                };

                const closeDetailModal = () => {
                    if (detailModal.open) {
                        detailModal.close();
                    }
                };

                document.querySelectorAll('[data-detail-trigger]').forEach((button) => {
                    button.addEventListener('click', () => {
                        const data = button.dataset;
                        detailTitle.textContent = data.detailTitle || 'Event proposal';

                        Object.entries(detailFields).forEach(([key, field]) => {
                            if (field) {
                                field.textContent = data[`detail${key.charAt(0).toUpperCase()}${key.slice(1)}`] || 'N/A';
                            }
                        });

                        if (data.detailSoftcopyUrl) {
                            softcopyLink.href = data.detailSoftcopyUrl;
                            softcopyLink.textContent = data.detailSoftcopyName || 'Download softcopy';
                            softcopyLink.hidden = false;
                            softcopyMissing.hidden = true;
                        } else {
                            softcopyLink.hidden = true;
                            softcopyMissing.hidden = false;
                        }

                        if (detailModal.showModal && !detailModal.open) {
                            detailModal.showModal();
                        }
                        detailModal.querySelector('.proposal-detail-close')?.focus();
                    });
                });

                detailModal.addEventListener('click', (event) => {
                    if (event.target === detailModal) {
                        closeDetailModal();
                    }
                });

                document.querySelectorAll('.proposal-detail-close').forEach((button) => {
                    button.addEventListener('click', closeDetailModal);
                });
            }
        });

        document.addEventListener('alpine:init', () => {
            Alpine.data('analyticsModal', () => ({
                loading: false,
                eventId: null,
                eventTitle: '',
                attendance: { total_demographic: 0, attended: 0, attendance_rate: 0 },
                evaluations: { total_submitted: 0, average_rating: 0, sentiments: { positive: 0, neutral: 0, negative: 0 } },
                demographics: [],

                async openModal(detail) {
                    this.eventId = detail.id;
                    this.eventTitle = detail.title;
                    this.loading = true;
                    
                    const modal = document.getElementById('analytics-modal');
                    if (modal?.showModal && !modal.open) {
                        modal.showModal();
                    }

                    try {
                        const response = await fetch(`/dashboard/events/${this.eventId}/analytics`);
                        if (!response.ok) throw new Error('Failed to retrieve analytics');
                        const data = await response.json();
                        
                        this.attendance = data.attendance;
                        this.evaluations = data.evaluations;
                        this.demographics = data.demographics || [];
                    } catch (e) {
                        console.error(e);
                        alert('Could not retrieve real-time analytics data. Please try again.');
                        this.closeModal();
                    } finally {
                        this.loading = false;
                    }
                },

                closeModal() {
                    const modal = document.getElementById('analytics-modal');
                    if (modal?.open) {
                        modal.close();
                    }
                    this.eventId = null;
                    this.eventTitle = '';
                },

                getPercentage(value, total) {
                    if (!total || total <= 0) return 0;
                    return Math.round((value / total) * 100);
                }
            }));
        });
    </script>

    @if($errors->any())
        <script>
            document.addEventListener('DOMContentLoaded', () => {
                const modal = document.getElementById('create-event-modal');
                if (modal?.showModal && !modal.open) {
                    modal.showModal();
                    setTimeout(() => document.querySelector('.form-input-error')?.focus(), 50);
                }
            });
        </script>
    @endif
</x-layouts.app>
