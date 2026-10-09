<x-layouts.app :title="'Workflow Task - '.$task->title.' - EAES'">
    @php
        $packet = $task->workflowRun->participationScope?->canonicalPpaPacket ?? $event->canonicalPpaPacket;
        $decisionApproved = \App\Models\ProposalWorkflowTask::DECISION_APPROVED;
        $decisionRejected = \App\Models\ProposalWorkflowTask::DECISION_REJECTED;
        $decisionRevisionRequested = \App\Models\ProposalWorkflowTask::DECISION_REVISION_REQUESTED;
        $decisionNotRequired = \App\Models\ProposalWorkflowTask::DECISION_NOT_REQUIRED;
        $statusAvailable = \App\Models\ProposalWorkflowTask::STATUS_AVAILABLE;
        $statusBlocked = \App\Models\ProposalWorkflowTask::STATUS_BLOCKED;
        $statusCompleted = in_array($task->status, [\App\Models\ProposalWorkflowTask::STATUS_APPROVED, \App\Models\ProposalWorkflowTask::STATUS_REJECTED, \App\Models\ProposalWorkflowTask::STATUS_REVISION_REQUESTED, \App\Models\ProposalWorkflowTask::STATUS_NOT_REQUIRED], true);
        $oldRevisionTarget = old('revision_target') ?: (old('revision_target_type') && old('revision_target_key') ? old('revision_target_type').':'.old('revision_target_key') : '');
        $isAssigned = $task->assigned_user_id === auth()->id();
        $canDecide = $isAssigned && $task->status === $statusAvailable;
        $isInspecting = auth()->user()?->hasRole('Super Admin (OSA)') && ! $isAssigned;
        $taskStatusLabel = function (?string $status): string {
            return match ($status) {
                \App\Models\ProposalWorkflowTask::STATUS_BLOCKED => 'Blocked',
                \App\Models\ProposalWorkflowTask::STATUS_AVAILABLE => 'Available',
                \App\Models\ProposalWorkflowTask::STATUS_IN_PROGRESS => 'Pending',
                \App\Models\ProposalWorkflowTask::STATUS_APPROVED => 'Approved',
                \App\Models\ProposalWorkflowTask::STATUS_REJECTED => 'Rejected',
                \App\Models\ProposalWorkflowTask::STATUS_REVISION_REQUESTED => 'Revision Requested',
                \App\Models\ProposalWorkflowTask::STATUS_NOT_REQUIRED => 'Not Required',
                default => filled($status) ? str($status)->replace('_', ' ')->title()->toString() : 'Unknown',
            };
        };
        $taskStatusClass = function (?string $status): string {
            return match ($status) {
                \App\Models\ProposalWorkflowTask::STATUS_APPROVED,
                \App\Models\ProposalWorkflowTask::STATUS_NOT_REQUIRED => 'border-emerald-200 bg-emerald-50 text-emerald-700',
                \App\Models\ProposalWorkflowTask::STATUS_AVAILABLE => 'border-sky-200 bg-sky-50 text-sky-700',
                \App\Models\ProposalWorkflowTask::STATUS_IN_PROGRESS => 'border-amber-200 bg-amber-50 text-amber-700',
                \App\Models\ProposalWorkflowTask::STATUS_REVISION_REQUESTED => 'border-rose-200 bg-rose-50 text-rose-700',
                \App\Models\ProposalWorkflowTask::STATUS_REJECTED => 'border-red-200 bg-red-50 text-red-700',
                default => 'border-slate-200 bg-slate-50 text-slate-600',
            };
        };
        $canonicalAttachments = $packet?->attachments ?? collect();
    @endphp

    <div class="space-y-6">
        <!-- Page Header -->
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <div class="flex items-center gap-2">
                    <p class="portal-page-kicker">Workflow Task #{{ $task->display_order }}</p>
                    <span class="rounded-full border px-2 py-0.5 text-xs font-semibold {{ $taskStatusClass($task->status) }}">
                        {{ $taskStatusLabel($task->status) }}
                    </span>
                </div>
                <h1 class="text-2xl font-display text-(--color-text-primary)">{{ $task->title }}</h1>
                <p class="text-sm text-(--color-text-secondary)">
                    {{ $event->title }} &bull; {{ $event->organization?->name ?? 'No organizer' }}
                    @if($event->organization?->acronym)
                        ({{ $event->organization->acronym }})
                    @endif
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <a class="btn btn-secondary" href="{{ route('dashboard.workflow-tasks.index') }}">Back to inbox</a>
                @if($packet)
                    <a class="btn btn-secondary" href="{{ route('dashboard.events.proposal-preview', $event) }}" target="_blank" rel="noopener">
                        Proposal preview
                    </a>
                    <a class="btn btn-secondary" href="{{ route('dashboard.events.permit-preview', $event) }}" target="_blank" rel="noopener">
                        Permit preview
                    </a>
                @endif
            </div>
        </div>

        @if(session('success'))
            <div class="alert alert-success">{{ session('success') }}</div>
        @endif
        @if($errors->any())
            <div class="alert alert-error">Please review the highlighted workflow task fields.</div>
        @endif

        <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_380px]">
            <!-- Main Content Area: Proposal identity, packet sections, attachments, and trail -->
            <div class="space-y-6">
                <!-- Proposal Identity & Summary -->
                <section class="card p-5" aria-labelledby="proposal-identity-heading">
                    <div class="flex items-center justify-between border-b border-(--color-border) pb-3">
                        <h2 id="proposal-identity-heading" class="font-semibold text-(--color-text-primary)">Proposal Identity & Overview</h2>
                        <span class="text-xs text-(--color-text-tertiary)">Run status: {{ str($task->workflowRun->status)->replace('_', ' ')->title() }}</span>
                    </div>

                    <dl class="mt-4 grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-3">
                        <div>
                            <dt class="proposal-detail-label">Proposal Title</dt>
                            <dd class="proposal-detail-value">{{ $packet?->title ?? $event->title }}</dd>
                        </div>
                        <div>
                            <dt class="proposal-detail-label">Category</dt>
                            <dd class="proposal-detail-value">{{ str($packet?->proposal_category ?? $event->proposal_category ?? 'activity')->replace('_', ' ')->title() }}</dd>
                        </div>
                        <div>
                            <dt class="proposal-detail-label">Event Status</dt>
                            <dd class="proposal-detail-value">{{ str($event->status)->replace('_', ' ')->title() }}</dd>
                        </div>
                        <div>
                            <dt class="proposal-detail-label">Organizer</dt>
                            <dd class="proposal-detail-value">{{ $event->organization?->name ?? 'N/A' }}</dd>
                        </div>
                        <div>
                            <dt class="proposal-detail-label">Proponent Lead</dt>
                            <dd class="proposal-detail-value">{{ $packet?->proponent_name ?? $event->proponent_name_snapshot ?? 'N/A' }}</dd>
                        </div>
                        <div>
                            <dt class="proposal-detail-label">Proponent Position</dt>
                            <dd class="proposal-detail-value">{{ $packet?->proponent_position ?? 'Officer' }}</dd>
                        </div>
                        <div>
                            <dt class="proposal-detail-label">Proponent Contact</dt>
                            <dd class="proposal-detail-value">{{ $packet?->proponent_email ?? 'N/A' }} @if($packet?->proponent_contact_number) &bull; {{ $packet->proponent_contact_number }} @endif</dd>
                        </div>
                        <div>
                            <dt class="proposal-detail-label">Schedule</dt>
                            <dd class="proposal-detail-value">
                                {{ $event->start_date?->format('M d, Y') }}
                                @if($event->end_date && ! $event->end_date->isSameDay($event->start_date))
                                    - {{ $event->end_date->format('M d, Y') }}
                                @endif
                            </dd>
                        </div>
                        <div>
                            <dt class="proposal-detail-label">Venue / Platform</dt>
                            <dd class="proposal-detail-value">
                                {{ $event->venue?->displayName() ?? $packet?->location_details ?? $event->location_details ?? 'N/A' }}
                                <span class="ml-1 inline-block text-xs text-(--color-text-tertiary)">({{ str($packet?->location_type ?? $event->location_type ?? 'on-campus')->replace('-', ' ')->title() }})</span>
                            </dd>
                        </div>
                        <div>
                            <dt class="proposal-detail-label">Total Budget</dt>
                            <dd class="proposal-detail-value">PHP {{ $packet?->budget_cost ? number_format((float) $packet->budget_cost, 2) : '0.00' }}</dd>
                        </div>
                        <div>
                            <dt class="proposal-detail-label">Source of Fund</dt>
                            <dd class="proposal-detail-value">{{ $packet?->source_of_fund ?: 'Not specified' }}</dd>
                        </div>
                        <div>
                            <dt class="proposal-detail-label">Expected Delegates</dt>
                            <dd class="proposal-detail-value">{{ $packet?->delegate_count ?? $event->expected_attendees ?? 'N/A' }} participants</dd>
                        </div>
                    </dl>
                </section>

                <!-- Relevant Canonical Packet Context Sections -->
                <section class="card p-5" aria-labelledby="packet-context-heading">
                    <div class="border-b border-(--color-border) pb-3">
                        <h2 id="packet-context-heading" class="font-semibold text-(--color-text-primary)">Canonical PPA Packet Details</h2>
                        <p class="text-xs text-(--color-text-tertiary)">Core objectives, activity methodology, and target outputs for this submission.</p>
                    </div>

                    <div class="mt-4 space-y-5 text-sm">
                        <!-- Objectives & Rationale -->
                        <div class="rounded-lg border border-(--color-border) p-4">
                            <h3 class="font-semibold text-(--color-text-primary)">Rationale & Objectives</h3>
                            <dl class="mt-3 grid gap-3">
                                <div>
                                    <dt class="proposal-detail-label">General Objective</dt>
                                    <dd class="mt-1 text-(--color-text-secondary)">{{ $packet?->general_objective ?: ($packet?->objectives ?: 'No general objective provided.') }}</dd>
                                </div>
                                @if($packet?->rationale)
                                    <div>
                                        <dt class="proposal-detail-label">Rationale</dt>
                                        <dd class="mt-1 text-(--color-text-secondary)">{{ $packet->rationale }}</dd>
                                    </div>
                                @endif
                                @if(!empty($packet?->specific_objectives))
                                    <div>
                                        <dt class="proposal-detail-label">Specific Objectives</dt>
                                        <dd class="mt-1">
                                            @if(is_array($packet->specific_objectives))
                                                <ul class="list-disc pl-5 space-y-1 text-(--color-text-secondary)">
                                                    @foreach($packet->specific_objectives as $objective)
                                                        <li>{{ is_array($objective) ? json_encode($objective) : $objective }}</li>
                                                    @endforeach
                                                </ul>
                                            @else
                                                <p class="text-(--color-text-secondary)">{{ $packet->specific_objectives }}</p>
                                            @endif
                                        </dd>
                                    </div>
                                @endif
                            </dl>
                        </div>

                        <!-- Methodology & Program of Activities -->
                        <div class="rounded-lg border border-(--color-border) p-4">
                            <h3 class="font-semibold text-(--color-text-primary)">Methodology & Program Activities</h3>
                            <dl class="mt-3 grid gap-3">
                                @if($packet?->methodology)
                                    <div>
                                        <dt class="proposal-detail-label">Methodology / Mechanics</dt>
                                        <dd class="mt-1 text-(--color-text-secondary)">{{ $packet->methodology }}</dd>
                                    </div>
                                @endif
                                @if(!empty($packet?->major_activities))
                                    <div>
                                        <dt class="proposal-detail-label">Major Activities</dt>
                                        <dd class="mt-1">
                                            @if(is_array($packet->major_activities))
                                                <ul class="list-disc pl-5 space-y-1 text-(--color-text-secondary)">
                                                    @foreach($packet->major_activities as $activity)
                                                        <li>{{ is_array($activity) ? json_encode($activity) : $activity }}</li>
                                                    @endforeach
                                                </ul>
                                            @else
                                                <p class="text-(--color-text-secondary)">{{ $packet->major_activities }}</p>
                                            @endif
                                        </dd>
                                    </div>
                                @endif
                                @if(!empty($packet?->program_activities))
                                    <div>
                                        <dt class="proposal-detail-label">Program Schedule</dt>
                                        <dd class="mt-1">
                                            @if(is_array($packet->program_activities))
                                                <ul class="list-disc pl-5 space-y-1 text-(--color-text-secondary)">
                                                    @foreach($packet->program_activities as $item)
                                                        <li>{{ is_array($item) ? (($item['time'] ?? '').(isset($item['time'], $item['activity']) ? ' - ' : '').($item['activity'] ?? ($item['title'] ?? json_encode($item)))) : $item }}</li>
                                                    @endforeach
                                                </ul>
                                            @else
                                                <p class="text-(--color-text-secondary)">{{ $packet->program_activities }}</p>
                                            @endif
                                        </dd>
                                    </div>
                                @endif
                            </dl>
                        </div>

                        <!-- Beneficiaries & Expected Outputs -->
                        <div class="rounded-lg border border-(--color-border) p-4">
                            <h3 class="font-semibold text-(--color-text-primary)">Beneficiaries & Expected Outputs</h3>
                            <dl class="mt-3 grid gap-3 sm:grid-cols-2">
                                <div>
                                    <dt class="proposal-detail-label">Expected Output</dt>
                                    <dd class="mt-1 text-(--color-text-secondary)">{{ $packet?->expected_output ?: 'Standard event outcomes and documentation.' }}</dd>
                                </div>
                                <div>
                                    <dt class="proposal-detail-label">Beneficiaries</dt>
                                    <dd class="mt-1 text-(--color-text-secondary)">{{ $packet?->beneficiaries ?: 'Students and general university community.' }}</dd>
                                </div>
                            </dl>
                        </div>
                    </div>
                </section>

                <!-- Supporting Attachments & Evidence -->
                <section class="card p-5" aria-labelledby="supporting-attachments-heading">
                    <div class="border-b border-(--color-border) pb-3">
                        <h2 id="supporting-attachments-heading" class="font-semibold text-(--color-text-primary)">Supporting Attachments & Evidence</h2>
                        <p class="text-xs text-(--color-text-tertiary)">Official files, endorsements, and venue confirmation evidence attached to this proposal packet.</p>
                    </div>

                    <div class="mt-4 space-y-3">
                        @php($hasFiles = false)

                        @if($event->proposal_document_path)
                            @php($hasFiles = true)
                            <div class="flex items-center justify-between gap-3 rounded-md border border-(--color-border) p-3">
                                <div>
                                    <p class="text-sm font-semibold text-(--color-text-primary)">
                                        {{ $event->proposal_document_original_name ?: 'Official PPA Proposal Softcopy' }}
                                    </p>
                                    <p class="text-xs text-(--color-text-tertiary)">PPA Proposal Document</p>
                                </div>
                                <a class="btn btn-sm btn-secondary" href="{{ route('dashboard.events.proposal-document', $event) }}">
                                    Download
                                </a>
                            </div>
                        @endif

                        @if($event->venue_confirmation_document_path)
                            @php($hasFiles = true)
                            <div class="flex items-center justify-between gap-3 rounded-md border border-(--color-border) p-3">
                                <div>
                                    <p class="text-sm font-semibold text-(--color-text-primary)">
                                        {{ $event->venue_confirmation_document_original_name ?: 'Venue Confirmation Document' }}
                                    </p>
                                    <p class="text-xs text-(--color-text-tertiary)">
                                        Venue confirmation &bull; Ref: {{ $packet?->venue_confirmation_reference_number ?? $event->venue_confirmation_reference ?? 'N/A' }}
                                    </p>
                                </div>
                                <a class="btn btn-sm btn-secondary" href="{{ route('dashboard.events.venue-evidence', $event) }}">
                                    Download Evidence
                                </a>
                            </div>
                        @endif

                        @foreach($canonicalAttachments as $attachment)
                            @php($hasFiles = true)
                            <div class="flex items-center justify-between gap-3 rounded-md border border-(--color-border) p-3">
                                <div>
                                    <p class="text-sm font-semibold text-(--color-text-primary)">{{ $attachment->original_name }}</p>
                                    <p class="text-xs text-(--color-text-tertiary)">
                                        <span class="uppercase">{{ str($attachment->attachment_type)->replace('_', ' ') }}</span>
                                        @if($attachment->size_bytes)
                                            &bull; {{ number_format($attachment->size_bytes / 1024, 1) }} KB
                                        @endif
                                        @if($attachment->uploadedBy)
                                            &bull; Uploaded by {{ $attachment->uploadedBy->name }}
                                        @endif
                                    </p>
                                </div>
                                <a class="btn btn-sm btn-secondary" href="{{ route('dashboard.events.ppa-attachments', [$event, $attachment]) }}">
                                    Download
                                </a>
                            </div>
                        @endforeach

                        @unless($hasFiles)
                            <div class="rounded-md border border-dashed border-(--color-border) p-4 text-center">
                                <p class="text-sm text-(--color-text-secondary)">No additional supporting files attached to this packet.</p>
                            </div>
                        @endunless
                    </div>
                </section>

                <!-- Approval Trail (Institutional Decisions) -->
                <section class="card p-5" aria-labelledby="approval-trail-heading">
                    <div class="border-b border-(--color-border) pb-3">
                        <h2 id="approval-trail-heading" class="font-semibold text-(--color-text-primary)">Approval Trail</h2>
                        <p class="text-xs text-(--color-text-tertiary)">Immutable institutional review decisions recorded for this workflow run.</p>
                    </div>

                    <div class="mt-4 space-y-3">
                        @forelse($task->workflowRun->approvalTrails as $trail)
                            <div class="rounded-md border border-(--color-border) p-3 text-sm">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="flex items-center gap-2">
                                        <span class="badge {{ in_array($trail->decision, [$decisionApproved, $decisionNotRequired], true) ? 'badge-approved' : 'badge-pending' }}">
                                            {{ str($trail->decision)->replace('_', ' ')->title() }}
                                        </span>
                                        <p class="font-semibold text-(--color-text-primary)">{{ $trail->reference_id }}</p>
                                    </div>
                                    <span class="text-xs text-(--color-text-tertiary)">{{ $trail->decided_at?->format('M d, Y g:i A') }}</span>
                                </div>
                                <p class="mt-1 text-xs text-(--color-text-tertiary)">
                                    Decided by {{ $trail->actor?->name ?? 'System' }} &bull; Role: {{ str($trail->assigned_role ?: 'Approver')->replace('_', ' ')->title() }}
                                </p>
                                @if(data_get($trail->metadata, 'revision_target.label'))
                                    <p class="mt-2 text-xs font-semibold uppercase text-rose-700">
                                        Target for correction: {{ data_get($trail->metadata, 'revision_target.label') }}
                                    </p>
                                @endif
                                <p class="mt-2 text-(--color-text-secondary)">
                                    {{ $trail->remarks ?: 'No remarks provided.' }}
                                </p>
                            </div>
                        @empty
                            <p class="text-sm text-(--color-text-secondary)">No approval trail decisions recorded yet.</p>
                        @endforelse
                    </div>
                </section>

                <!-- Reassignment History -->
                @if($task->assignmentHistory->isNotEmpty())
                    <section class="card p-5" aria-labelledby="assignment-history-heading">
                        <h2 id="assignment-history-heading" class="font-semibold text-(--color-text-primary)">Assignment History</h2>
                        <div class="mt-4 space-y-3">
                            @foreach($task->assignmentHistory as $history)
                                <div class="rounded-md border border-(--color-border) p-3 text-sm">
                                    <p class="font-semibold text-(--color-text-primary)">
                                        {{ $history->formerAssignee?->name ?? 'Unassigned' }} &rarr; {{ $history->newAssignee?->name ?? 'Unknown' }}
                                    </p>
                                    <p class="text-xs text-(--color-text-tertiary)">
                                        Reassigned by {{ $history->actor?->name ?? 'System' }} &bull; {{ $history->reassigned_at?->format('M d, Y g:i A') }}
                                    </p>
                                    <p class="mt-2 text-(--color-text-secondary)">{{ $history->reason }}</p>
                                </div>
                            @endforeach
                        </div>
                    </section>
                @endif
            </div>

            <!-- Sidebar Column: Workflow Context, Decision Controls, and Reassignment -->
            <aside class="space-y-6">
                <!-- Decision Controls Card -->
                <section class="card p-5" aria-labelledby="decision-controls-heading">
                    <h2 id="decision-controls-heading" class="font-semibold text-(--color-text-primary)">Decision Controls</h2>

                    @if($canDecide)
                        <form method="POST" action="{{ route('dashboard.workflow-tasks.decide', $task) }}" class="mt-4 space-y-4">
                            @csrf
                            <div>
                                <label class="form-label" for="decision">Review Outcome <span class="text-rose-600">*</span></label>
                                <select id="decision" name="decision" class="form-input @error('decision') form-input-error @enderror" required>
                                    <option value="">Select outcome</option>
                                    <option value="{{ $decisionApproved }}" @selected(old('decision') === $decisionApproved)>Approved</option>
                                    <option value="{{ $decisionRevisionRequested }}" @selected(old('decision') === $decisionRevisionRequested)>Revision Requested</option>
                                    <option value="{{ $decisionRejected }}" @selected(old('decision') === $decisionRejected)>Rejected</option>
                                    @if(! $task->processStep?->is_required || data_get($task->processStep?->condition_config, 'allow_not_required'))
                                        <option value="{{ $decisionNotRequired }}" @selected(old('decision') === $decisionNotRequired)>Not Required</option>
                                    @endif
                                </select>
                                @error('decision') <p class="form-error">{{ $message }}</p> @enderror
                            </div>

                            <div>
                                <label class="form-label" for="revision-target">Revision Target</label>
                                <select id="revision-target" name="revision_target" class="form-input @error('revision_target_key') form-input-error @enderror @error('revision_target') form-input-error @enderror">
                                    <option value="">Select target for revision requests</option>
                                    @foreach($revisionTargetGroups as $groupLabel => $revisionTargets)
                                        <optgroup label="{{ $groupLabel }}">
                                            @foreach($revisionTargets as $revisionTarget)
                                                @php($revisionTargetValue = $revisionTarget['type'].':'.$revisionTarget['key'])
                                                <option value="{{ $revisionTargetValue }}" @selected($oldRevisionTarget === $revisionTargetValue)>{{ $revisionTarget['label'] }}</option>
                                            @endforeach
                                        </optgroup>
                                    @endforeach
                                </select>
                                <p class="mt-1 text-xs text-(--color-text-tertiary)">Required only when requesting revisions.</p>
                                @error('revision_target_key') <p class="form-error">{{ $message }}</p> @enderror
                                @error('revision_target') <p class="form-error">{{ $message }}</p> @enderror
                            </div>

                            <div>
                                <label class="form-label" for="remarks">Remarks</label>
                                <textarea id="remarks" name="remarks" class="form-input @error('remarks') form-input-error @enderror" rows="4" maxlength="2000" placeholder="Required for reject and revision requests. Provide clear instructions or rationale.">{{ old('remarks') }}</textarea>
                                <p class="mt-1 text-xs text-(--color-text-tertiary)">Remarks are required before rejecting or returning for revision.</p>
                                @error('remarks') <p class="form-error">{{ $message }}</p> @enderror
                            </div>

                            <button type="submit" class="btn btn-primary w-full">Record Decision</button>
                        </form>
                    @elseif($task->status === $statusBlocked)
                        <div class="mt-3 rounded-md border border-slate-200 bg-slate-50/80 p-4 text-sm text-slate-700">
                            <div class="flex items-center gap-2 font-semibold text-slate-800">
                                <span class="h-2 w-2 rounded-full bg-slate-400"></span>
                                Decision Controls Locked
                            </div>
                            <p class="mt-2 text-xs text-slate-600">
                                This step cannot be decided until preceding prerequisite approvals have been completed.
                            </p>
                            @if($task->processStep?->prerequisiteSteps?->isNotEmpty())
                                <div class="mt-3 border-t border-slate-200 pt-3">
                                    <p class="text-xs font-semibold uppercase text-slate-500">Prerequisite Steps:</p>
                                    <ul class="mt-1 list-disc pl-5 text-xs space-y-1">
                                        @foreach($task->processStep->prerequisiteSteps as $prereq)
                                            <li>{{ $prereq->title }}</li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif
                        </div>
                    @elseif($statusCompleted)
                        <div class="mt-3 rounded-md border border-emerald-200 bg-emerald-50/70 p-4 text-sm text-emerald-900">
                            <div class="flex items-center justify-between">
                                <p class="font-semibold">Decision Completed</p>
                                <span class="rounded-full border px-2 py-0.5 text-xs font-semibold {{ $taskStatusClass($task->status) }}">
                                    {{ $taskStatusLabel($task->status) }}
                                </span>
                            </div>
                            <dl class="mt-3 space-y-2 text-xs">
                                <div>
                                    <dt class="font-semibold text-emerald-800">Decided By</dt>
                                    <dd>{{ $task->decidedBy?->name ?? $task->assignedUser?->name ?? 'Approver' }}</dd>
                                </div>
                                <div>
                                    <dt class="font-semibold text-emerald-800">Recorded At</dt>
                                    <dd>{{ $task->decided_at?->format('M d, Y g:i A') ?? $task->completed_at?->format('M d, Y g:i A') ?? 'N/A' }}</dd>
                                </div>
                                <div>
                                    <dt class="font-semibold text-emerald-800">Remarks</dt>
                                    <dd class="italic">{{ $task->remarks ?: 'No remarks provided.' }}</dd>
                                </div>
                            </dl>
                            <p class="mt-3 text-xs text-emerald-700">
                                This workflow task is completed and permanently recorded in the institutional Approval Trail.
                            </p>
                        </div>
                    @elseif($isInspecting)
                        <div class="mt-3 rounded-md border border-sky-200 bg-sky-50/70 p-4 text-sm text-sky-900">
                            <p class="font-semibold">Administrative Inspection</p>
                            <p class="mt-1 text-xs text-sky-700">
                                You are viewing this task under Super Admin (OSA) authority. Decision authority belongs to the assigned approver: <strong>{{ $task->assignedUser?->name ?? 'Unassigned' }}</strong>.
                            </p>
                        </div>
                    @else
                        <div class="mt-3 rounded-md border border-slate-200 bg-slate-50 p-4 text-xs text-slate-600">
                            Decision controls are inactive for this task state.
                        </div>
                    @endif
                </section>

                <!-- Workflow Context: Process Rail -->
                <section class="card p-5" aria-labelledby="workflow-process-rail-heading">
                    <div class="flex items-center justify-between border-b border-(--color-border) pb-3">
                        <div>
                            <h2 id="workflow-process-rail-heading" class="font-semibold text-(--color-text-primary)">Workflow Process Rail</h2>
                            <p class="text-xs text-(--color-text-tertiary)">Institutional sequence for this proposal</p>
                        </div>
                        <span class="rounded-full border px-2 py-0.5 text-xs font-semibold border-slate-200 bg-slate-50 text-slate-700">
                            {{ $task->workflowRun->tasks->count() }} Steps
                        </span>
                    </div>

                    <div class="mt-4 space-y-3">
                        @foreach($task->workflowRun->tasks as $checkTask)
                            @php($isCurrent = $checkTask->id === $task->id)
                            <div class="rounded-md border p-3 text-sm {{ $isCurrent ? 'border-sky-300 bg-sky-50/60 ring-1 ring-sky-300' : 'border-(--color-border)' }}">
                                <div class="flex items-start justify-between gap-3">
                                    <div>
                                        <div class="flex items-center gap-1.5">
                                            <span class="font-bold text-(--color-text-primary)">{{ $checkTask->display_order }}.</span>
                                            <p class="font-semibold text-(--color-text-primary)">{{ $checkTask->title }}</p>
                                        </div>
                                        <p class="mt-0.5 text-xs text-(--color-text-tertiary)">
                                            {{ $checkTask->assignedUser?->name ?? 'Unassigned' }}
                                            ({{ str($checkTask->assigned_role ?: 'approver')->replace('_', ' ')->title() }})
                                        </p>
                                    </div>
                                    <span class="rounded-full border px-2 py-0.5 text-xs font-semibold {{ $taskStatusClass($checkTask->status) }}">
                                        {{ $taskStatusLabel($checkTask->status) }}
                                    </span>
                                </div>
                                @if($isCurrent)
                                    <span class="mt-2 inline-block rounded bg-sky-100 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wider text-sky-800">
                                        Current Workspace
                                    </span>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </section>

                <!-- Assignment Snapshot Card -->
                <section class="card p-5" aria-labelledby="assignment-summary-heading">
                    <h2 id="assignment-summary-heading" class="font-semibold text-(--color-text-primary)">Assignment Summary</h2>
                    <dl class="mt-4 space-y-3 text-sm">
                        <div>
                            <dt class="proposal-detail-label">Assigned Approver</dt>
                            <dd class="proposal-detail-value">{{ $task->assignedUser?->name ?? 'Unassigned' }}</dd>
                        </div>
                        <div>
                            <dt class="proposal-detail-label">Assigned Email</dt>
                            <dd class="proposal-detail-value">{{ $task->assignedUser?->email ?? 'N/A' }}</dd>
                        </div>
                        <div>
                            <dt class="proposal-detail-label">Authority Role</dt>
                            <dd class="proposal-detail-value">{{ str($task->assigned_role ?: 'approver')->replace('_', ' ')->title() }}</dd>
                        </div>
                        <div>
                            <dt class="proposal-detail-label">Governance Scope</dt>
                            <dd class="proposal-detail-value">{{ str($task->assigned_scope ?: 'university')->replace('_', ' ')->title() }}</dd>
                        </div>
                        <div>
                            <dt class="proposal-detail-label">Available Since</dt>
                            <dd class="proposal-detail-value">{{ $task->available_at?->format('M d, Y g:i A') ?? 'Awaiting prerequisites' }}</dd>
                        </div>
                    </dl>
                </section>

                <!-- OSA Reassignment Controls -->
                @if(auth()->user()?->hasRole('Super Admin (OSA)') && in_array($task->status, [\App\Models\ProposalWorkflowTask::STATUS_BLOCKED, \App\Models\ProposalWorkflowTask::STATUS_AVAILABLE, \App\Models\ProposalWorkflowTask::STATUS_IN_PROGRESS], true))
                    <section class="card p-5" aria-labelledby="reassign-task-heading">
                        <h2 id="reassign-task-heading" class="font-semibold text-(--color-text-primary)">Reassign Task</h2>
                        <p class="text-xs text-(--color-text-tertiary)">OSA administrative reassignment with mandatory audit trail reason.</p>
                        <form method="POST" action="{{ route('dashboard.workflow-tasks.reassign', $task) }}" class="mt-4 space-y-4">
                            @csrf
                            <div>
                                <label class="form-label" for="new-assignee">Replacement Approver <span class="text-rose-600">*</span></label>
                                <select id="new-assignee" name="new_assignee_id" class="form-input @error('new_assignee_id') form-input-error @enderror" required>
                                    <option value="">Select replacement</option>
                                    @foreach($eligibleReplacements as $replacement)
                                        <option value="{{ $replacement->id }}" @selected(old('new_assignee_id') === $replacement->id)>
                                            {{ $replacement->name }} ({{ $replacement->email }})
                                        </option>
                                    @endforeach
                                </select>
                                @error('new_assignee_id') <p class="form-error">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="form-label" for="reason">Reason for Reassignment <span class="text-rose-600">*</span></label>
                                <textarea id="reason" name="reason" class="form-input @error('reason') form-input-error @enderror" rows="3" maxlength="1000" placeholder="Provide institutional rationale for reassigning this step." required>{{ old('reason') }}</textarea>
                                @error('reason') <p class="form-error">{{ $message }}</p> @enderror
                            </div>
                            <button type="submit" class="btn btn-primary w-full">Reassign Task</button>
                        </form>
                    </section>
                @endif
            </aside>
        </div>
    </div>
</x-layouts.app>
