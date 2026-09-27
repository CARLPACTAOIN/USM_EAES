<div class="space-y-4">
<section class="proposal-modal-section">
    @if($notice)
        <div class="event-workflow-feedback event-workflow-feedback--success" role="status">{{ $notice }}</div>
    @endif

    @if($errorNotice)
        <div class="event-workflow-feedback event-workflow-feedback--error" role="alert">{{ $errorNotice }}</div>
    @endif

    <div class="flex flex-col gap-1 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <h3 class="font-semibold text-sm text-text-primary">Scope Register</h3>
            <p class="text-xs text-text-tertiary">Participant PPA actions update here without leaving the modal.</p>
        </div>
        <span class="badge badge-pending">{{ $eventScopes->count() }} {{ Str::plural('scope', $eventScopes->count()) }}</span>
    </div>

    <div class="mt-3 space-y-3">
        @forelse($eventScopes as $scope)
            @php
                $actions = $scopeActionState[$scope->id] ?? [];
                $submitTarget = "submitParticipantPpa('{$scope->id}')";
                $ackTarget = "acknowledgeInvitation('{$scope->id}')";
                $reviewTarget = "reviewParticipantPpa('{$scope->id}')";
                $approveTarget = "approveParticipantPpa('{$scope->id}')";
                $revisionTarget = "requestParticipantPpaRevision('{$scope->id}')";
                $rejectTarget = "rejectParticipantPpa('{$scope->id}')";
            @endphp
            <div class="rounded-lg border border-(--color-border) bg-(--color-surface-raised) p-3">
                <div class="grid gap-3 xl:grid-cols-[minmax(0,1fr)_minmax(18rem,auto)]">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <p class="text-sm font-semibold text-text-primary">{{ $scope->displayName() }}</p>
                            <span class="badge badge-{{ $scope->participation_role === 'organizer' ? 'approved' : 'pending' }}">{{ ucfirst($scope->participation_role) }}</span>
                            <span class="badge badge-{{ str_replace('_', '-', $scope->ppa_status) }}">{{ ucwords(str_replace('_', ' ', $scope->ppa_status)) }}</span>
                        </div>
                        <div class="mt-2 grid gap-1 text-xs text-text-secondary">
                            <span>{{ ucwords(str_replace('_', ' ', $scope->participant_type)) }} · {{ ucwords(str_replace('_', ' ', $scope->capture_mode)) }}</span>
                            @if($scope->canonicalPpaPacket)
                                <div class="flex flex-wrap items-center gap-2 text-xs text-text-primary font-medium mt-1">
                                    <span class="badge badge-secondary">Canonical PPA Packet</span>
                                    <span>Delegates: {{ $scope->canonicalPpaPacket->delegate_count ?? 'N/A' }}</span>
                                    <span>Budget: PHP {{ number_format((float) ($scope->canonicalPpaPacket->budget_cost ?? 0), 2) }}</span>
                                    @if($scope->canonicalPpaPacket->supporting_document_path || $scope->ppa_softcopy_path)
                                        <a href="{{ route('dashboard.events.participant-scopes.evidence', [$event->id, $scope->id]) }}" class="text-primary hover:underline flex items-center gap-1">
                                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                            Evidence File
                                        </a>
                                    @endif
                                    <a href="{{ route('dashboard.events.participant-scopes.preview', [$event->id, $scope->id]) }}" target="_blank" class="text-primary hover:underline">
                                        Preview Packet ↗
                                    </a>
                                </div>
                            @endif
                            <span>
                                Late cutoff:
                                @if($event->event_late_cutoff_min !== null)
                                    {{ $event->event_late_cutoff_min }} min event policy
                                @elseif($scope->late_cutoff_min !== null)
                                    {{ $scope->late_cutoff_min }} min scope policy
                                @else
                                    Default policy
                                @endif
                            </span>
                            <span>{{ $scope->analytics_rollup_enabled ? 'Included in roll-up analytics' : 'Excluded from roll-up analytics' }}</span>
                            @if($scope->participation_role === \App\Models\EventParticipationScope::ROLE_PARTICIPANT)
                                <span class="{{ $scope->invitation_acknowledged_at ? 'text-success' : 'text-warning' }}">
                                    {{ $scope->invitation_acknowledged_at ? 'Invitation acknowledged ' . $scope->invitation_acknowledged_at->diffForHumans() : 'Invitation not acknowledged' }}
                                </span>
                            @endif
                            @if($scope->review_remarks)
                                <span class="text-warning">Review note: {{ $scope->review_remarks }}</span>
                            @endif
                        </div>
                    </div>

                    <div class="grid content-start gap-2">
                        @if($actions['canAcknowledge'] ?? false)
                            <button type="button"
                                    class="btn btn-sm btn-primary justify-center"
                                    wire:click="acknowledgeInvitation('{{ $scope->id }}')"
                                    wire:loading.attr="disabled"
                                    wire:target="{{ $ackTarget }}">
                                <span wire:loading.remove wire:target="{{ $ackTarget }}">Acknowledge Invitation</span>
                                <span wire:loading wire:target="{{ $ackTarget }}">Acknowledging...</span>
                            </button>
                        @endif

                        @if($actions['canSubmit'] ?? false)
                            <div class="rounded border border-(--color-border) bg-(--color-surface-muted) p-3 text-xs space-y-3">
                                <div class="rounded bg-sky-50 dark:bg-sky-950/40 border border-sky-200 dark:border-sky-800 p-2 text-[0.75rem] text-sky-900 dark:text-sky-200 space-y-1">
                                    <p class="font-semibold">Parent Event Venue & Dates (Inherited Read-Only):</p>
                                    <p><strong>Venue:</strong> {{ $event->location_details ?: ($event->venue?->name ?? 'Campus Venue') }} ({{ ucwords(str_replace('-', ' ', (string) $event->location_type)) }})</p>
                                    <p><strong>Dates:</strong> {{ $event->start_date?->toFormattedDateString() }} - {{ $event->end_date?->toFormattedDateString() }}</p>
                                    <p class="text-[0.7rem] text-sky-700 dark:text-sky-300">No separate venue booking is required. The parent event's approved permit covers participant scopes.</p>
                                </div>

                                <div class="grid gap-2 sm:grid-cols-2">
                                    <div>
                                        <label class="font-medium text-text-secondary">Proponent Name</label>
                                        <input type="text"
                                               class="form-input text-xs w-full mt-0.5"
                                               placeholder="Coordinator / Officer Name"
                                               wire:model="packetForms.{{ $scope->id }}.proponent_name">
                                    </div>
                                    <div>
                                        <label class="font-medium text-text-secondary">Position</label>
                                        <input type="text"
                                               class="form-input text-xs w-full mt-0.5"
                                               placeholder="President / Representative"
                                               wire:model="packetForms.{{ $scope->id }}.proponent_position">
                                    </div>
                                    <div>
                                        <label class="font-medium text-text-secondary">Organization</label>
                                        <input type="text"
                                               class="form-input text-xs w-full mt-0.5"
                                               wire:model="packetForms.{{ $scope->id }}.proponent_organization">
                                    </div>
                                    <div>
                                        <label class="font-medium text-text-secondary">Email Address</label>
                                        <input type="email"
                                               class="form-input text-xs w-full mt-0.5"
                                               wire:model="packetForms.{{ $scope->id }}.proponent_email">
                                    </div>
                                    <div>
                                        <label class="font-medium text-text-secondary">Delegate Count</label>
                                        <input type="number"
                                               min="1"
                                               class="form-input text-xs w-full mt-0.5"
                                               placeholder="Expected delegates"
                                               wire:model="packetForms.{{ $scope->id }}.delegate_count">
                                    </div>
                                    <div>
                                        <label class="font-medium text-text-secondary">Source of Fund</label>
                                        <input type="text"
                                               class="form-input text-xs w-full mt-0.5"
                                               placeholder="e.g. Org Funds, College Subsidy"
                                               wire:model="packetForms.{{ $scope->id }}.source_of_fund">
                                    </div>
                                    <div class="sm:col-span-2">
                                        <label class="font-medium text-text-secondary">Budget Contribution / Cost (PHP)</label>
                                        <input type="number"
                                               step="0.01"
                                               min="0"
                                               class="form-input text-xs w-full mt-0.5"
                                               placeholder="0.00"
                                               wire:model="packetForms.{{ $scope->id }}.budget_cost">
                                    </div>
                                    <div class="sm:col-span-2">
                                        <label class="font-medium text-text-secondary">Supporting Checklist Evidence (Delegate list, travel order, endorsement)</label>
                                        <input type="file"
                                               class="form-input text-xs w-full mt-0.5"
                                               accept=".pdf,.doc,.docx"
                                               wire:model="supportingDocuments.{{ $scope->id }}">
                                        @if($packetForms[$scope->id]['has_supporting_document'] ?? false)
                                            <p class="mt-1 text-[0.7rem] text-success">
                                                Attached evidence: {{ $packetForms[$scope->id]['supporting_document_name'] ?? 'File uploaded' }}
                                            </p>
                                        @endif
                                    </div>
                                </div>

                                <div class="flex flex-wrap items-center justify-end gap-2 pt-2 border-t border-(--color-border)">
                                    <button type="button"
                                            class="btn btn-xs btn-secondary"
                                            wire:click="saveParticipantDraft('{{ $scope->id }}')"
                                            wire:loading.attr="disabled">
                                        Save Draft
                                    </button>
                                    <button type="button"
                                            class="btn btn-xs btn-primary"
                                            wire:click="submitParticipantPacket('{{ $scope->id }}')"
                                            wire:loading.attr="disabled">
                                        Submit Packet
                                    </button>
                                </div>
                            </div>
                        @endif

                        @if($actions['canReview'] ?? false)
                            <button type="button"
                                    class="btn btn-sm btn-secondary justify-center"
                                    wire:click="reviewParticipantPpa('{{ $scope->id }}')"
                                    wire:loading.attr="disabled"
                                    wire:target="{{ $reviewTarget }}">
                                <span wire:loading.remove wire:target="{{ $reviewTarget }}">Review</span>
                                <span wire:loading wire:target="{{ $reviewTarget }}">Reviewing...</span>
                            </button>
                        @endif

                        @if($actions['canFinalize'] ?? false)
                            <div class="grid gap-2 xl:grid-cols-3">
                                <button type="button"
                                        class="btn btn-sm justify-center"
                                        style="background-color: var(--color-success); color: white;"
                                        wire:click="approveParticipantPpa('{{ $scope->id }}')"
                                        wire:loading.attr="disabled"
                                        wire:target="{{ $approveTarget }}">
                                    <span wire:loading.remove wire:target="{{ $approveTarget }}">Approve</span>
                                    <span wire:loading wire:target="{{ $approveTarget }}">Approving...</span>
                                </button>
                                <form wire:submit="requestParticipantPpaRevision('{{ $scope->id }}')" class="grid gap-2">
                                    <input type="text"
                                           class="form-input text-xs @error('reviewRemarks.'.$scope->id) form-input-error @enderror"
                                           placeholder="Revision note"
                                           wire:model="reviewRemarks.{{ $scope->id }}">
                                    @error('reviewRemarks.'.$scope->id)
                                        <p class="form-error" role="alert">{{ $message }}</p>
                                    @enderror
                                    <button type="submit"
                                            class="btn btn-sm btn-secondary justify-center"
                                            wire:loading.attr="disabled"
                                            wire:target="{{ $revisionTarget }}">
                                        <span wire:loading.remove wire:target="{{ $revisionTarget }}">Request Revision</span>
                                        <span wire:loading wire:target="{{ $revisionTarget }}">Returning...</span>
                                    </button>
                                </form>
                                <form wire:submit="rejectParticipantPpa('{{ $scope->id }}')" class="grid gap-2">
                                    <input type="text"
                                           class="form-input text-xs @error('reviewRemarks.'.$scope->id) form-input-error @enderror"
                                           placeholder="Rejection note"
                                           wire:model="reviewRemarks.{{ $scope->id }}">
                                    @error('reviewRemarks.'.$scope->id)
                                        <p class="form-error" role="alert">{{ $message }}</p>
                                    @enderror
                                    <button type="submit"
                                            class="btn btn-sm btn-destructive justify-center"
                                            wire:loading.attr="disabled"
                                            wire:target="{{ $rejectTarget }}">
                                        <span wire:loading.remove wire:target="{{ $rejectTarget }}">Reject</span>
                                        <span wire:loading wire:target="{{ $rejectTarget }}">Rejecting...</span>
                                    </button>
                                </form>
                            </div>
                        @endif

                        @if(!($actions['canAcknowledge'] ?? false) && !($actions['canSubmit'] ?? false) && !($actions['canReview'] ?? false) && !($actions['canFinalize'] ?? false))
                            <span class="rounded-lg border border-(--color-border) bg-(--color-surface-muted) px-3 py-2 text-center text-xs font-medium text-text-tertiary">
                                No action needed
                            </span>
                        @endif
                    </div>
                </div>
            </div>
        @empty
            <div class="rounded-lg border border-dashed border-(--color-border) bg-(--color-surface-muted) p-4">
                <p class="text-sm font-medium text-text-primary">No participant scopes yet</p>
                <p class="mt-1 text-xs text-text-tertiary">The organizer scope will be generated when the proposal is saved or backfilled by migration.</p>
            </div>
        @endforelse
    </div>
 </section>

@if($canAddScopes)
    <section class="proposal-modal-section participant-audience-builder"
             x-data="participantAudienceBuilder({
                audienceMode: $wire.entangle('audienceMode'),
                selectedCollegeIds: $wire.entangle('selectedCollegeIds'),
                selectedDepartmentIds: $wire.entangle('selectedDepartmentIds'),
                selectedOrganizationIds: $wire.entangle('selectedOrganizationIds'),
                collegeQuery: $wire.entangle('collegeQuery'),
                departmentQuery: $wire.entangle('departmentQuery'),
                organizationQuery: $wire.entangle('organizationQuery'),
                captureMode: $wire.entangle('captureMode'),
                ppaRequired: $wire.entangle('ppaRequired'),
                analyticsRollupEnabled: $wire.entangle('analyticsRollupEnabled'),
                colleges: @js($collegeOptions->map(fn ($college) => [
                    'id' => $college->id,
                    'code' => $college->code,
                    'name' => $college->name,
                ])->values()),
                departments: @js($departmentOptions->map(fn ($department) => [
                    'id' => $department->id,
                    'code' => $department->code,
                    'name' => $department->name,
                    'college_code' => $department->college?->code,
                    'college_name' => $department->college?->name,
                ])->values()),
                organizations: @js($organizationOptions->map(fn ($organization) => [
                    'id' => $organization->id,
                    'acronym' => $organization->acronym,
                    'name' => $organization->name,
                    'college_code' => $organization->college?->code,
                    'college_name' => $organization->college?->name,
                ])->values()),
             })">
        <div class="flex flex-col gap-1 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h3 class="font-semibold text-sm text-text-primary">Add Participant Scope</h3>
                <p class="text-xs text-text-tertiary">Choose who can participate; each selected College or org becomes its own scope.</p>
            </div>
            <p class="participant-audience-count" aria-live="polite" x-text="selectionLabel()">
                @if($audienceMode === 'all')
                    {{ $selectionCount }} College {{ Str::plural('scope', $selectionCount) }}
                @elseif($selectionCount === 0)
                    No audience selected
                @else
                    {{ $selectionCount }} selected
                @endif
            </p>
        </div>

        <form wire:submit="addParticipantScopes" class="mt-4 grid gap-4">
            <div class="participant-audience-segments" role="group" aria-label="Audience mode">
                <button type="button"
                        class="participant-audience-segment {{ $audienceMode === 'all' ? 'is-active' : '' }}"
                        x-bind:class="{ 'is-active': audienceMode === 'all' }"
                        x-bind:aria-pressed="audienceMode === 'all' ? 'true' : 'false'"
                        x-on:click="setAudienceMode('all')">
                    All can participate
                </button>
                <button type="button"
                        class="participant-audience-segment {{ $audienceMode === 'college' ? 'is-active' : '' }}"
                        x-bind:class="{ 'is-active': audienceMode === 'college' }"
                        x-bind:aria-pressed="audienceMode === 'college' ? 'true' : 'false'"
                        x-on:click="setAudienceMode('college')">
                    College
                </button>
                <button type="button"
                        class="participant-audience-segment {{ $audienceMode === 'department' ? 'is-active' : '' }}"
                        x-bind:class="{ 'is-active': audienceMode === 'department' }"
                        x-bind:aria-pressed="audienceMode === 'department' ? 'true' : 'false'"
                        x-on:click="setAudienceMode('department')">
                    Department
                </button>
                <button type="button"
                        class="participant-audience-segment {{ $audienceMode === 'organization' ? 'is-active' : '' }}"
                        x-bind:class="{ 'is-active': audienceMode === 'organization' }"
                        x-bind:aria-pressed="audienceMode === 'organization' ? 'true' : 'false'"
                        x-on:click="setAudienceMode('organization')">
                    Specific org
                </button>
            </div>

            @error('audienceMode') <p class="form-error" role="alert">{{ $message }}</p> @enderror

            <div class="participant-audience-panel" x-show="audienceMode === 'all'" x-cloak>
                    <p class="text-sm font-medium text-text-primary">Every College audience will be invited.</p>
                    <p class="mt-1 text-xs text-text-secondary">This creates one College participant scope per College so scanner access, PPAs, acknowledgements, and analytics stay separate.</p>
            </div>

            <div class="participant-audience-panel" x-show="audienceMode === 'college'" x-cloak>
                    <label for="scope-college-search-{{ $event->id }}" class="text-xs font-semibold text-text-secondary">Find Colleges</label>
                    <input id="scope-college-search-{{ $event->id }}"
                           type="search"
                           class="form-input text-sm mt-1"
                           placeholder="Search College name or code"
                           x-model.debounce.150ms="collegeQuery">
                    @error('selectedCollegeIds') <p class="form-error mt-2" role="alert">{{ $message }}</p> @enderror
                    <div class="participant-audience-list mt-3">
                        <template x-for="college in filteredColleges()" :key="college.id">
                            <label class="participant-audience-option">
                                <input type="checkbox"
                                       x-bind:value="college.id"
                                       x-model="selectedCollegeIds">
                                <span>
                                    <strong x-text="college.code"></strong>
                                    <small x-text="college.name"></small>
                                </span>
                            </label>
                        </template>
                        <p x-show="filteredColleges().length === 0" x-cloak class="rounded-lg border border-dashed border-(--color-border) bg-(--color-surface-muted) p-3 text-xs text-text-tertiary">No Colleges match the search.</p>
                    </div>
            </div>

            <div class="participant-audience-panel" x-show="audienceMode === 'department'" x-cloak>
                    <label for="scope-dept-search-{{ $event->id }}" class="text-xs font-semibold text-text-secondary">Find Departments</label>
                    <input id="scope-dept-search-{{ $event->id }}"
                           type="search"
                           class="form-input text-sm mt-1"
                           placeholder="Search Department code, name, or College"
                           x-model.debounce.150ms="departmentQuery">
                    @error('selectedDepartmentIds') <p class="form-error mt-2" role="alert">{{ $message }}</p> @enderror
                    <div class="participant-audience-list mt-3">
                        <template x-for="department in filteredDepartments()" :key="department.id">
                            <label class="participant-audience-option">
                                <input type="checkbox"
                                       x-bind:value="department.id"
                                       x-model="selectedDepartmentIds">
                                <span>
                                    <strong x-text="department.code"></strong>
                                    <small x-text="department.name + (department.college_code ? ' - ' + department.college_code : '')"></small>
                                </span>
                            </label>
                        </template>
                        <p x-show="filteredDepartments().length === 0" x-cloak class="rounded-lg border border-dashed border-(--color-border) bg-(--color-surface-muted) p-3 text-xs text-text-tertiary">No departments match the search.</p>
                    </div>
            </div>

            <div class="participant-audience-panel" x-show="audienceMode === 'organization'" x-cloak>
                    <label for="scope-org-search-{{ $event->id }}" class="text-xs font-semibold text-text-secondary">Find Organizations</label>
                    <input id="scope-org-search-{{ $event->id }}"
                           type="search"
                           class="form-input text-sm mt-1"
                           placeholder="Search org acronym, name, or College"
                           x-model.debounce.150ms="organizationQuery">
                    @error('selectedOrganizationIds') <p class="form-error mt-2" role="alert">{{ $message }}</p> @enderror
                    <div class="participant-audience-list mt-3">
                        <template x-for="organization in filteredOrganizations()" :key="organization.id">
                            <label class="participant-audience-option">
                                <input type="checkbox"
                                       x-bind:value="organization.id"
                                       x-model="selectedOrganizationIds">
                                <span>
                                    <strong x-text="organization.acronym || organization.name"></strong>
                                    <small x-text="organization.name + (organization.college_code ? ' - ' + organization.college_code : '')"></small>
                                </span>
                            </label>
                        </template>
                        <p x-show="filteredOrganizations().length === 0" x-cloak class="rounded-lg border border-dashed border-(--color-border) bg-(--color-surface-muted) p-3 text-xs text-text-tertiary">No organizations match the search.</p>
                    </div>
            </div>

            <div class="grid gap-3 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] lg:items-end">
                <div>
                    <label for="scope-capture-{{ $event->id }}" class="text-xs font-semibold text-text-secondary">Capture mode</label>
                    <select id="scope-capture-{{ $event->id }}" x-model="captureMode" class="form-input form-select text-sm mt-1" required>
                        <option value="self_scanning">Self scanning</option>
                        <option value="parent_scanning">Parent scanning</option>
                        <option value="delegated_scanning">Delegated scanning</option>
                        <option value="central_gate">Central gate</option>
                    </select>
                    @error('captureMode') <p class="form-error mt-1" role="alert">{{ $message }}</p> @enderror
                </div>
                <div class="participant-audience-flags">
                    <label class="inline-flex items-center gap-2">
                        <input type="checkbox" x-model="ppaRequired">
                        PPA required
                    </label>
                    <label class="inline-flex items-center gap-2">
                        <input type="checkbox" x-model="analyticsRollupEnabled">
                        Roll-up
                    </label>
                </div>
                <button type="submit"
                        class="btn btn-sm btn-primary"
                        wire:loading.attr="disabled"
                        wire:target="addParticipantScopes">
                    <span wire:loading.remove wire:target="addParticipantScopes" x-text="submitLabel()">
                        {{ $selectionCount > 1 ? 'Add ' . $selectionCount . ' scopes' : 'Add Scope' }}
                    </span>
                    <span wire:loading wire:target="addParticipantScopes">Adding...</span>
                </button>
            </div>
        </form>
    </section>
@endif
</div>
