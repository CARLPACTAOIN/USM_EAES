    @php
        $workspace = $workspace ?? 'organized';
        $isParticipatingWorkspace = $workspace === 'participating';
        $currentStatus = $this->status;
        $routeParams = function (array $overrides = []) use ($workspace, $currentStatus, $search): array {
            return collect([
                'workspace' => $workspace !== 'organized' ? $workspace : null,
                'status' => $currentStatus !== '' ? $currentStatus : null,
                'q' => $search !== '' ? $search : null,
            ])->merge($overrides)->filter(fn ($value) => filled($value))->all();
        };
    @endphp

<div class="events-workspace"
     x-data="{
        eventSearch: @js($search),
        normalizedEventSearch() {
            return (this.eventSearch || '').toString().trim().toLowerCase();
        },
        eventRowVisible(searchText) {
            const needle = this.normalizedEventSearch();
            return !needle || (searchText || '').toString().toLowerCase().includes(needle);
        },
        visibleEventRows() {
            return Array.from(this.$root.querySelectorAll('[data-event-search]'))
                .filter((row) => this.eventRowVisible(row.dataset.eventSearch || ''))
                .length;
        }
     }">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-8">
        <div>
            <h1 class="text-2xl font-display text-(--color-text-primary)">Event Proposals</h1>
            <p class="text-sm text-(--color-text-secondary) mt-1">
                {{ $isParticipatingWorkspace ? 'Submit invited participant PPAs and manage approved scoped scanners' : 'Manage owned PPA event proposals and participant invitations' }}
            </p>
        </div>
        @if($canCreateProposals)
        <button type="button" onclick="const modal = document.getElementById('create-event-modal'); modal.showModal(); setTimeout(() => document.getElementById('title')?.focus(), 50)" class="btn btn-primary shrink-0">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
            New Event
        </button>
        @endif
    </div>

    <div class="events-nav-row mobile-scroll-pills mb-4" role="group" aria-label="Event proposal workspace">
        <a href="{{ route('dashboard.events', $routeParams(['workspace' => null])) }}"
           wire:click.prevent="setWorkspace('organized')"
           aria-pressed="{{ !$isParticipatingWorkspace ? 'true' : 'false' }}"
           class="btn btn-sm {{ !$isParticipatingWorkspace ? 'btn-primary' : 'btn-secondary' }}">
            Organized Events
            <span class="ml-1 text-xs opacity-80">{{ $organizedEventsCount ?? 0 }}</span>
        </a>
        <a href="{{ route('dashboard.events', $routeParams(['workspace' => 'participating'])) }}"
           wire:click.prevent="setWorkspace('participating')"
           aria-pressed="{{ $isParticipatingWorkspace ? 'true' : 'false' }}"
           class="btn btn-sm {{ $isParticipatingWorkspace ? 'btn-primary' : 'btn-secondary' }}">
            Participating Events
            <span class="ml-1 text-xs opacity-80">{{ $participatingEventsCount ?? 0 }}</span>
        </a>
        <span wire:loading.flex
              wire:target="setWorkspace,setStatus,applyFilters,clearSearch,search,nextPage,previousPage,gotoPage"
              class="events-inline-loading">
            <svg class="h-3 w-3 animate-spin" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
            </svg>
            Updating
        </span>
    </div>

    <div class="events-status-row mobile-scroll-pills mb-4" role="group" aria-label="Proposal status">
        <a href="{{ route('dashboard.events', $routeParams(['status' => null])) }}"
           wire:click.prevent="setStatus"
           aria-pressed="{{ $currentStatus === '' ? 'true' : 'false' }}"
           class="btn btn-sm {{ $currentStatus === '' ? 'btn-primary' : 'btn-secondary' }}">All</a>
        @foreach($statusFilters as $status)
        <a href="{{ route('dashboard.events', $routeParams(['status' => $status])) }}"
           wire:click.prevent="setStatus('{{ $status }}')"
           aria-pressed="{{ $currentStatus === $status ? 'true' : 'false' }}"
           class="btn btn-sm {{ $currentStatus === $status ? 'btn-primary' : 'btn-secondary' }}">
            {{ ucwords(str_replace('_', ' ', $status)) }}
        </a>
        @endforeach
    </div>

    <form method="GET"
          action="{{ route('dashboard.events') }}"
          wire:submit.prevent="applyFilters"
          class="events-search-inline mb-4"
          role="search">
        <input type="hidden" name="workspace" value="{{ $workspace }}">
        <input type="hidden" name="status" value="{{ $currentStatus }}">

        <label for="events-workspace-search" class="sr-only">Search events</label>
        <div class="events-search-compact">
            <svg class="events-search-compact-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M21 21l-4.35-4.35m1.1-5.15a6.25 6.25 0 11-12.5 0 6.25 6.25 0 0112.5 0z"/>
            </svg>
            <input id="events-workspace-search"
                   type="search"
                   name="q"
                   x-ref="eventSearchInput"
                   x-model.debounce.150ms="eventSearch"
                   wire:model="search"
                   wire:input.debounce.350ms="searchTyped($event.target.value)"
                   autocomplete="off"
                   enterkeyhint="search"
                   placeholder="Search events"
                   class="events-search-compact-input">
            <button type="button"
                    x-show="normalizedEventSearch() !== ''"
                    x-cloak
                    @click="eventSearch = ''; $refs.eventSearchInput?.focus()"
                    wire:click="clearSearch"
                    class="events-search-clear"
                    aria-label="Clear event search">
                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
    </form>

    @if($search !== '')
        <p class="events-search-summary" aria-live="polite">
            Showing {{ $events->total() }} matching {{ Str::plural('proposal', $events->total()) }}
        </p>
    @endif

    {{-- Events Table --}}
    <div class="card overflow-hidden events-table-card"
         wire:loading.class="opacity-60"
         wire:target="setWorkspace,setStatus,applyFilters,clearSearch,search,nextPage,previousPage,gotoPage">
        <div class="overflow-x-auto events-table-scroll">
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
                        $eventScopes = $event->participationScopes;
                        $participantScopes = $eventScopes
                            ->where('participation_role', \App\Models\EventParticipationScope::ROLE_PARTICIPANT)
                            ->values();
                        $primaryParticipantScope = $isParticipatingWorkspace ? $participantScopes->first() : null;
                        $target = $event->target_demographics ?? [];
                        $budget = $event->budget_allocations ?? [];
                        $formatEventTime = function ($time): string {
                            if (!$time) {
                                return 'N/A';
                            }

                            try {
                                return \Carbon\Carbon::parse($time)->format('g:i A');
                            } catch (\Throwable) {
                                return substr((string) $time, 0, 5);
                            }
                        };
                        $eventDays = $event->eventDays
                            ->sortBy('day_number')
                            ->values();
                        $eventDaysSummary = $eventDays
                            ->map(function ($day) use ($formatEventTime) {
                                $timeRange = trim($formatEventTime($day->start_time) . ' - ' . $formatEventTime($day->end_time));

                                return 'D' . $day->day_number . ': ' . ($day->date?->format('M d, Y') ?? 'N/A') . ' ' . $timeRange;
                            })
                            ->implode(' | ');
                        $eventStartTime = $eventDays->first() ? $formatEventTime($eventDays->first()->start_time) : 'N/A';
                        $eventEndTime = $eventDays->last() ? $formatEventTime($eventDays->last()->end_time) : 'N/A';
                        $scheduleSummary = ($event->start_date?->format('M d, Y') ?? 'N/A')
                            . (($event->end_date && $event->start_date && $event->end_date->ne($event->start_date)) ? ' - ' . $event->end_date->format('M d, Y') : '');
                        $eventEvaluationQuestions = \App\Support\EventEvaluationForm::questionsForEvent($event);
                        $eventEvaluationQuestionsSummary = collect($eventEvaluationQuestions)
                            ->map(fn ($question, $index) => ($index + 1) . '. ' . $question['label'] . ($question['description'] ? ' - ' . $question['description'] : ''))
                            ->implode(' | ');
                        $budgetCost = isset($budget['budget_cost']) && $budget['budget_cost'] !== null && $budget['budget_cost'] !== ''
                            ? 'PHP ' . number_format((float) $budget['budget_cost'], 2)
                            : 'N/A';
                        $approvedScopeCount = $eventScopes
                            ->filter(fn ($scope) => $scope->status === 'active' && (!$scope->ppa_required || in_array($scope->ppa_status, ['approved', 'not_required'], true)))
                            ->count();
                        $participantScopeCount = $participantScopes->count();
                        $isEventOrganizerAdmin = !$isParticipatingWorkspace
                            && $canCreateProposals
                            && $currentAdminScope?->organizationId
                            && $event->organization_id === $currentAdminScope->organizationId;
                        $canUseAttendanceRules = !$user->hasRole('Super Admin (OSA)')
                            && (
                                $isEventOrganizerAdmin
                                || ($isParticipatingWorkspace && $primaryParticipantScope && $primaryParticipantScope->current_admin_owns_scope)
                            );

                        // --- Exposed CTA & attention dot logic ---
                        $needsHardcopy = !$isParticipatingWorkspace && $canCreateProposals && $event->status === 'draft' && !$event->hardcopy_submitted;
                        $needsRulesAttention = $canUseAttendanceRules && !$event->attendance_policy_locked && $event->event_late_cutoff_min === null && in_array($event->status, ['draft', 'submitted', 'approved'], true);
                        $overflowNeedsAttention = $needsHardcopy || $needsRulesAttention;
                    @endphp
                    <tr wire:key="events-workspace-row-{{ $event->id }}-{{ $workspace }}"
                        data-event-search="{{ Str::lower($event->title . ' ' . ($event->organization?->name ?? '') . ' ' . ($event->organization?->acronym ?? '') . ' ' . ($event->proposal_category ?? '') . ' ' . $event->status . ' ' . $scheduleSummary) }}"
                        x-show="eventRowVisible($el.dataset.eventSearch)">
                        <td data-label="Event">
                            <p class="font-medium text-(--color-text-primary)">{{ $event->title }}</p>
                            <p class="text-xs text-(--color-text-tertiary)">
                                {{ ucfirst($event->proposal_category ?? 'activity') }} &middot; {{ $event->eventDays->count() }} day(s)
                            </p>
                            @if($event->parentEvent)
                                <p class="text-xs text-(--color-text-tertiary)">Parent: {{ Str::limit($event->parentEvent->title, 32) }}</p>
                            @endif
                        </td>
                        <td data-label="Organization">
                            <span class="text-sm text-(--color-text-secondary)">{{ $event->organization?->acronym ?? 'N/A' }}</span>
                        </td>
                        <td data-label="Dates">
                            <div class="date-cell" title="{{ $eventDaysSummary ?: $scheduleSummary }}">
                                <span class="date-cell-range">
                                    {{ $event->start_date?->format('M d') }}@if($event->end_date && $event->end_date->ne($event->start_date)) – {{ $event->end_date->format('M d') }}@endif, {{ $event->start_date?->format('Y') }}
                                </span>
                                <span class="date-cell-time">
                                    {{ $eventStartTime }} – {{ $eventEndTime }}
                                </span>
                                @if($event->eventDays->count() > 1)
                                    <span class="date-cell-days">
                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                                        {{ $event->eventDays->count() }} days
                                    </span>
                                @endif
                            </div>
                        </td>
                        <td data-label="PPA Packet" class="events-table-secondary">
                            <livewire:dashboard.event-proposal-packet
                                :event-id="$event->id"
                                :is-participating-workspace="$isParticipatingWorkspace"
                                :primary-participant-scope-id="$primaryParticipantScope?->id"
                                :key="'event-proposal-packet-'.$event->id.'-'.$workspace"
                            />
                        </td>
                        <td data-label="Status">
                            <livewire:dashboard.event-proposal-status
                                :event-id="$event->id"
                                :is-participating-workspace="$isParticipatingWorkspace"
                                :primary-participant-scope-id="$primaryParticipantScope?->id"
                                :key="'event-proposal-status-'.$event->id.'-'.$workspace"
                            />
                        </td>
                        <td data-label="Exports" class="events-table-secondary">
                            @if(!$isParticipatingWorkspace)
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
                            @else
                                <span class="text-xs text-(--color-text-tertiary)">Event owner export</span>
                            @endif
                        </td>
                        <td data-label="Actions" class="text-right">
                            <div class="event-actions-cell">
                                {{-- ═══ EXPOSED PRIMARY CTAs ═══ --}}
                                <livewire:dashboard.event-proposal-workflow
                                    :event-id="$event->id"
                                    :is-participating-workspace="$isParticipatingWorkspace"
                                    :primary-participant-scope-id="$primaryParticipantScope?->id"
                                    :key="'event-proposal-workflow-'.$event->id.'-'.$workspace"
                                />

                                {{-- ═══ OVERFLOW DROPDOWN (secondary actions) ═══ --}}
                                <details class="event-action-menu" data-event-action-menu>
                                    <summary class="btn btn-sm btn-secondary event-action-menu-toggle {{ $overflowNeedsAttention ? 'event-action-menu-toggle--attention' : '' }}"
                                             aria-label="More actions for {{ $event->title }}"
                                             aria-controls="event-actions-panel-{{ $event->id }}"
                                             aria-expanded="false"
                                             title="{{ $overflowNeedsAttention ? 'Action needed — open for details' : 'More actions' }}">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M6.75 12h.008v.008H6.75V12zm5.25 0h.008v.008H12V12zm5.25 0h.008v.008h-.008V12z"/></svg>
                                        <svg class="event-action-menu-chevron w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M6 9l6 6 6-6"/></svg>
                                    </summary>
                                    <div id="event-actions-panel-{{ $event->id }}" class="event-action-menu-panel">
                                        <div class="event-action-menu-header">
                                            <span class="event-action-menu-kicker">More actions</span>
                                            <span class="event-action-menu-title">{{ Str::limit($event->title, 34) }}</span>
                                        </div>
                                        <div class="event-action-menu-list events-mobile-actions">
                                    {{-- OSA: View details --}}
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
                                            data-detail-start-time="{{ $eventStartTime }}"
                                            data-detail-end-time="{{ $eventEndTime }}"
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
                                            data-detail-adviser-signed="{{ $event->adviser_signed ? 'Signed' : 'Pending' }}"
                                            data-detail-evaluation-questions="{{ $eventEvaluationQuestionsSummary ?: 'No evaluation questions recorded' }}">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M2.25 12s3.75-6.75 9.75-6.75S21.75 12 21.75 12 18 18.75 12 18.75 2.25 12 2.25 12z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                        View Details
                                    </button>
                                    @endif

                                    {{-- Scopes --}}
                                    <button type="button"
                                            class="btn btn-sm btn-secondary"
                                            title="{{ $isParticipatingWorkspace ? 'Your participant PPA scope' : 'Manage participant scopes' }}"
                                            onclick="document.getElementById('participant-scope-modal-{{ $event->id }}')?.showModal()">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M8.25 6.75h7.5M8.25 12h7.5m-7.5 5.25h4.5M4.5 5.25A2.25 2.25 0 016.75 3h10.5a2.25 2.25 0 012.25 2.25v13.5A2.25 2.25 0 0117.25 21H6.75a2.25 2.25 0 01-2.25-2.25V5.25z"/></svg>
                                        @if($isParticipatingWorkspace && $primaryParticipantScope && in_array($primaryParticipantScope->ppa_status, ['draft', 'rejected'], true))
                                            Submit PPA
                                        @else
                                            Scopes
                                        @endif
                                    </button>

                                    {{-- Attendance Rules --}}
                                    @if($canUseAttendanceRules)
                                    <button type="button"
                                            class="btn btn-sm btn-secondary"
                                            title="Manage attendance rules{{ $needsRulesAttention ? ' — not yet configured' : '' }}"
                                            onclick="document.getElementById('attendance-rules-modal-{{ $event->id }}')?.showModal()">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 12.75l2 2 4-5.25M5.25 4.5h13.5A1.5 1.5 0 0120.25 6v12a1.5 1.5 0 01-1.5 1.5H5.25A1.5 1.5 0 013.75 18V6a1.5 1.5 0 011.5-1.5z"/></svg>
                                        Rules @if($needsRulesAttention)<span style="color: #e04545; font-size: 0.65rem; margin-left: 0.25rem;">● Setup needed</span>@endif
                                    </button>
                                    @endif

                                    {{-- Scanners --}}
                                    @if(!$user->hasRole('Super Admin (OSA)') && $user->can('assign-scanners-own') && in_array($event->status, ['approved', 'completed']) && $approvedScopeCount > 0)
                                    <button type="button"
                                            class="btn btn-sm btn-secondary"
                                            title="Manage scanner assignments"
                                            onclick="document.getElementById('scanner-session-modal-{{ $event->id }}')?.showModal()">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m9-4.13a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                                        Scanners
                                    </button>
                                    @endif

                                    {{-- AI Sentiment --}}
                                    @if(!$isParticipatingWorkspace && ($event->unprocessed_comment_count ?? 0) > 0)
                                    @livewire('dashboard.event-sentiment-action', [
                                        'eventId' => $event->id,
                                        'pendingComments' => $event->unprocessed_comment_count,
                                    ], key('event-sentiment-action-' . $event->id . '-' . $workspace))
                                    @endif
                                        </div>
                                    </div>
                                </details>
                            </div>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="7">
                            <div class="empty-state py-8">
                                <svg class="empty-state-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                                <p class="empty-state-title">
                                    @if($search !== '')
                                        No matching proposals
                                    @else
                                        {{ $isParticipatingWorkspace ? 'No participant invitations found' : 'No events found' }}
                                    @endif
                                </p>
                                <p class="empty-state-description">
                                    @if($search !== '')
                                        Try the event title, organization acronym, or proposal category.
                                    @else
                                        {{ $isParticipatingWorkspace ? 'Invited events appear here after the organizer submits the parent proposal.' : 'Create your first event proposal to get started.' }}
                                    @endif
                                </p>
                            </div>
                        </td>
                    </tr>
                    @endforelse
                    @if($events->count() > 0)
                    <tr x-show="normalizedEventSearch() !== '' && visibleEventRows() === 0" class="events-client-empty-row">
                        <td colspan="7">
                            <div class="empty-state py-8">
                                <svg class="empty-state-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                                <p class="empty-state-title">No matching proposals</p>
                                <p class="empty-state-description">Try another title, organization, status, or category.</p>
                            </div>
                        </td>
                    </tr>
                    @endif
                </tbody>
            </table>
        </div>

        @if($events->hasPages())
        <div class="card-footer">
            {{ $events->links() }}
        </div>
        @endif
    </div>

    @foreach($events as $event)
        <dialog id="participant-scope-modal-{{ $event->id }}"
                class="proposal-modal modal-md"
                aria-labelledby="participant-scope-modal-title-{{ $event->id }}"
                onclick="if (event.target === this) this.close()">
            <div class="proposal-modal-form">
                <div class="proposal-modal-header">
                    <div class="proposal-modal-title-row">
                        <span class="proposal-modal-icon" aria-hidden="true">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M8.25 6.75h7.5M8.25 12h7.5m-7.5 5.25h4.5M4.5 5.25A2.25 2.25 0 016.75 3h10.5a2.25 2.25 0 012.25 2.25v13.5A2.25 2.25 0 0117.25 21H6.75a2.25 2.25 0 01-2.25-2.25V5.25z"/></svg>
                        </span>
                        <div>
                            <p class="proposal-modal-kicker">{{ $event->title }}</p>
                            <h2 id="participant-scope-modal-title-{{ $event->id }}" class="proposal-modal-title">Participant Scopes</h2>
                            <p class="proposal-modal-subtitle">Track invited participant PPAs, scanner ownership, and analytics roll-up boundaries.</p>
                        </div>
                    </div>
                    <button type="button" class="btn-icon btn-ghost" aria-label="Close participant scopes" onclick="document.getElementById('participant-scope-modal-{{ $event->id }}')?.close()">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>

                <div class="proposal-modal-body space-y-4">
                    @livewire('dashboard.event-participant-scopes', [
                        'eventId' => $event->id,
                        'isParticipatingWorkspace' => $isParticipatingWorkspace,
                    ], key('event-participant-scopes-' . $event->id . '-' . $workspace))
                </div>

                <div class="proposal-modal-footer">
                    <p class="proposal-required-note">Approved runtime scopes unlock scoped scanner access</p>
                    <div class="proposal-footer-actions">
                        <button type="button" class="btn btn-secondary" onclick="document.getElementById('participant-scope-modal-{{ $event->id }}')?.close()">Close</button>
                    </div>
                </div>
            </div>
        </dialog>

        <dialog id="attendance-rules-modal-{{ $event->id }}"
                class="proposal-modal modal-md"
                aria-labelledby="attendance-rules-modal-title-{{ $event->id }}"
                onclick="if (event.target === this) this.close()">
            <div class="proposal-modal-form">
                <div class="proposal-modal-header">
                    <div class="proposal-modal-title-row">
                        <span class="proposal-modal-icon" aria-hidden="true">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M9 12.75l2 2 4-5.25M5.25 4.5h13.5A1.5 1.5 0 0120.25 6v12a1.5 1.5 0 01-1.5 1.5H5.25A1.5 1.5 0 013.75 18V6a1.5 1.5 0 011.5-1.5z"/></svg>
                        </span>
                        <div>
                            <p class="proposal-modal-kicker">{{ $event->title }}</p>
                            <h2 id="attendance-rules-modal-title-{{ $event->id }}" class="proposal-modal-title">Attendance Rules</h2>
                            <p class="proposal-modal-subtitle">Set the official late cutoff before scanning begins. OSA does not review this rule.</p>
                        </div>
                    </div>
                    <button type="button" class="btn-icon btn-ghost" aria-label="Close attendance rules" onclick="document.getElementById('attendance-rules-modal-{{ $event->id }}')?.close()">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>

                @livewire('dashboard.event-attendance-rules', [
                    'eventId' => $event->id,
                    'isParticipatingWorkspace' => $isParticipatingWorkspace,
                ], key('event-attendance-rules-' . $event->id . '-' . $workspace))

                <div class="proposal-modal-footer">
                    <p class="proposal-required-note">Rules lock when the event starts</p>
                    <div class="proposal-footer-actions">
                        <button type="button" class="btn btn-secondary" onclick="document.getElementById('attendance-rules-modal-{{ $event->id }}')?.close()">Close</button>
                    </div>
                </div>
            </div>
        </dialog>

        @if(in_array($event->status, ['approved', 'completed']))
        <dialog id="scanner-session-modal-{{ $event->id }}"
                class="proposal-modal modal-md"
                aria-labelledby="scanner-session-modal-title-{{ $event->id }}"
                onclick="if (event.target === this) this.close()">
            <div class="proposal-modal-form">
                <div class="proposal-modal-header">
                    <div class="proposal-modal-title-row">
                        <span class="proposal-modal-icon" aria-hidden="true">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m9-4.13a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                        </span>
                        <div>
                            <p class="proposal-modal-kicker">{{ $event->title }}</p>
                            <h2 id="scanner-session-modal-title-{{ $event->id }}" class="proposal-modal-title">Scanner Assignments</h2>
                            <p class="proposal-modal-subtitle">Assign eligible student/officer accounts, then monitor their device-bound scanner sessions.</p>
                        </div>
                    </div>
                    <button type="button" class="btn-icon btn-ghost" aria-label="Close scanner sessions" onclick="document.getElementById('scanner-session-modal-{{ $event->id }}')?.close()">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>

                @livewire('dashboard.event-scanner-access', ['eventId' => $event->id], key('scanner-access-' . $event->id))

                <div class="proposal-modal-footer">
                    <p class="proposal-required-note">Existing attendance data is preserved</p>
                    <div class="proposal-footer-actions">
                        <button type="button" class="btn btn-secondary" onclick="document.getElementById('scanner-session-modal-{{ $event->id }}')?.close()">Close</button>
                    </div>
                </div>
            </div>
        </dialog>
        @endif
    @endforeach

</div>
