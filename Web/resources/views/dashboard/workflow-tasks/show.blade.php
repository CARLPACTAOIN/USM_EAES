<x-layouts.app :title="'Workflow Task - EAES'">
    @php($packet = $event->canonicalPpaPacket)
    @php($decisionApproved = \App\Models\ProposalWorkflowTask::DECISION_APPROVED)
    @php($decisionRejected = \App\Models\ProposalWorkflowTask::DECISION_REJECTED)
    @php($decisionRevisionRequested = \App\Models\ProposalWorkflowTask::DECISION_REVISION_REQUESTED)
    @php($decisionNotRequired = \App\Models\ProposalWorkflowTask::DECISION_NOT_REQUIRED)
    @php($statusAvailable = \App\Models\ProposalWorkflowTask::STATUS_AVAILABLE)
    <div class="space-y-6">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <p class="portal-page-kicker">Workflow task</p>
                <h1 class="text-2xl font-display text-(--color-text-primary)">{{ $task->title }}</h1>
                <p class="text-sm text-(--color-text-secondary)">{{ $event->title }} | {{ $event->organization?->name ?? 'No organizer' }}</p>
            </div>
            <a class="btn btn-secondary" href="{{ route('dashboard.workflow-tasks.index') }}">Back to inbox</a>
        </div>

        @if(session('success'))
            <div class="alert alert-success">{{ session('success') }}</div>
        @endif
        @if($errors->any())
            <div class="alert alert-error">Please review the highlighted workflow task fields.</div>
        @endif

        <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_360px]">
            <div class="space-y-6">
                <section class="card p-5">
                    <h2 class="font-semibold text-(--color-text-primary)">Proposal Packet</h2>
                    <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                        <div><dt class="proposal-detail-label">Category</dt><dd class="proposal-detail-value">{{ $packet?->proposal_category ?? $event->proposal_category ?? 'N/A' }}</dd></div>
                        <div><dt class="proposal-detail-label">Schedule</dt><dd class="proposal-detail-value">{{ $event->start_date?->format('M d, Y') }} @if($event->end_date && !$event->end_date->isSameDay($event->start_date)) - {{ $event->end_date->format('M d, Y') }} @endif</dd></div>
                        <div><dt class="proposal-detail-label">Venue / platform</dt><dd class="proposal-detail-value">{{ $event->venue?->displayName() ?? $packet?->location_details ?? $event->location_details ?? 'N/A' }}</dd></div>
                        <div><dt class="proposal-detail-label">Budget</dt><dd class="proposal-detail-value">{{ $packet?->budget_cost ? number_format((float) $packet->budget_cost, 2) : 'N/A' }}</dd></div>
                        <div class="sm:col-span-2"><dt class="proposal-detail-label">Objective</dt><dd class="proposal-detail-value">{{ $packet?->general_objective ?? 'N/A' }}</dd></div>
                    </dl>
                    <div class="mt-4 flex flex-wrap gap-2">
                        <a class="btn btn-sm btn-secondary" href="{{ route('dashboard.events.proposal-preview', $event) }}" target="_blank" rel="noopener">Proposal preview</a>
                        <a class="btn btn-sm btn-secondary" href="{{ route('dashboard.events.permit-preview', $event) }}" target="_blank" rel="noopener">Permit preview</a>
                        @if($event->proposal_document_path)
                            <a class="btn btn-sm btn-secondary" href="{{ route('dashboard.events.proposal-document', $event) }}">Attached PPA</a>
                        @endif
                        @if($event->venue_confirmation_document_path)
                            <a class="btn btn-sm btn-secondary" href="{{ route('dashboard.events.venue-evidence', $event) }}">Venue evidence</a>
                        @endif
                    </div>
                </section>

                <section class="card p-5">
                    <h2 class="font-semibold text-(--color-text-primary)">Checklist</h2>
                    <div class="mt-4 space-y-3">
                        @foreach($task->workflowRun->tasks as $checkTask)
                            <div class="flex items-start justify-between gap-4 rounded-md border border-(--color-border) p-3">
                                <div>
                                    <p class="text-sm font-semibold text-(--color-text-primary)">{{ $checkTask->display_order }}. {{ $checkTask->title }}</p>
                                    <p class="text-xs text-(--color-text-tertiary)">Assigned to {{ $checkTask->assignedUser?->name ?? 'Unassigned' }}</p>
                                </div>
                                <span class="badge {{ in_array($checkTask->status, ['approved', 'not_required'], true) ? 'badge-approved' : 'badge-pending' }}">{{ str($checkTask->status)->replace('_', ' ')->title() }}</span>
                            </div>
                        @endforeach
                    </div>
                </section>
            </div>

            <aside class="space-y-6">
                @if($task->assigned_user_id === auth()->id() && $task->status === $statusAvailable)
                    <section class="card p-5">
                        <h2 class="font-semibold text-(--color-text-primary)">Decision</h2>
                        <form method="POST" action="{{ route('dashboard.workflow-tasks.decide', $task) }}" class="mt-4 space-y-4">
                            @csrf
                            <div>
                                <label class="form-label" for="decision">Outcome</label>
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
                                <label class="form-label" for="remarks">Remarks</label>
                                <textarea id="remarks" name="remarks" class="form-input @error('remarks') form-input-error @enderror" rows="4" maxlength="2000">{{ old('remarks') }}</textarea>
                                @error('remarks') <p class="form-error">{{ $message }}</p> @enderror
                            </div>
                            <button type="submit" class="btn btn-primary">Record Decision</button>
                        </form>
                    </section>
                @endif

                <section class="card p-5">
                    <h2 class="font-semibold text-(--color-text-primary)">Assignment</h2>
                    <dl class="mt-4 space-y-3 text-sm">
                        <div><dt class="proposal-detail-label">Current approver</dt><dd class="proposal-detail-value">{{ $task->assignedUser?->name ?? 'Unassigned' }}</dd></div>
                        <div><dt class="proposal-detail-label">Role</dt><dd class="proposal-detail-value">{{ str($task->assigned_role ?: 'approver')->replace('_', ' ')->title() }}</dd></div>
                        <div><dt class="proposal-detail-label">Status</dt><dd class="proposal-detail-value">{{ str($task->status)->replace('_', ' ')->title() }}</dd></div>
                    </dl>
                </section>

                @if(auth()->user()?->hasRole('Super Admin (OSA)'))
                    <section class="card p-5">
                        <h2 class="font-semibold text-(--color-text-primary)">Reassign Task</h2>
                        <form method="POST" action="{{ route('dashboard.workflow-tasks.reassign', $task) }}" class="mt-4 space-y-4">
                            @csrf
                            <div>
                                <label class="form-label" for="new-assignee">Replacement approver</label>
                                <select id="new-assignee" name="new_assignee_id" class="form-input @error('new_assignee_id') form-input-error @enderror" required>
                                    <option value="">Select replacement</option>
                                    @foreach($eligibleReplacements as $replacement)
                                        <option value="{{ $replacement->id }}" @selected(old('new_assignee_id') === $replacement->id)>{{ $replacement->name }} | {{ $replacement->email }}</option>
                                    @endforeach
                                </select>
                                @error('new_assignee_id') <p class="form-error">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="form-label" for="reason">Reason</label>
                                <textarea id="reason" name="reason" class="form-input @error('reason') form-input-error @enderror" rows="4" maxlength="1000" required>{{ old('reason') }}</textarea>
                                @error('reason') <p class="form-error">{{ $message }}</p> @enderror
                            </div>
                            <button type="submit" class="btn btn-primary">Reassign</button>
                        </form>
                    </section>
                @endif

                <section class="card p-5">
                    <h2 class="font-semibold text-(--color-text-primary)">Approval Trail</h2>
                    <div class="mt-4 space-y-3">
                        @forelse($task->workflowRun->approvalTrails as $trail)
                            <div class="rounded-md border border-(--color-border) p-3 text-sm">
                                <div class="flex items-start justify-between gap-3">
                                    <p class="font-semibold text-(--color-text-primary)">{{ str($trail->decision)->replace('_', ' ')->title() }}</p>
                                    <span class="badge {{ in_array($trail->decision, [$decisionApproved, $decisionNotRequired], true) ? 'badge-approved' : 'badge-pending' }}">{{ $trail->reference_id }}</span>
                                </div>
                                <p class="text-xs text-(--color-text-tertiary)">By {{ $trail->actor?->name ?? 'System' }} | {{ $trail->decided_at?->format('M d, Y g:i A') }}</p>
                                <p class="mt-2 text-(--color-text-secondary)">{{ $trail->remarks ?: 'No remarks provided.' }}</p>
                            </div>
                        @empty
                            <p class="text-sm text-(--color-text-secondary)">No approval trail decisions yet.</p>
                        @endforelse
                    </div>
                </section>

                <section class="card p-5">
                    <h2 class="font-semibold text-(--color-text-primary)">Assignment History</h2>
                    <div class="mt-4 space-y-3">
                        @forelse($task->assignmentHistory as $history)
                            <div class="rounded-md border border-(--color-border) p-3 text-sm">
                                <p class="font-semibold text-(--color-text-primary)">{{ $history->formerAssignee?->name ?? 'Unassigned' }} to {{ $history->newAssignee?->name ?? 'Unknown' }}</p>
                                <p class="text-xs text-(--color-text-tertiary)">By {{ $history->actor?->name ?? 'System' }} | {{ $history->reassigned_at?->format('M d, Y g:i A') }}</p>
                                <p class="mt-2 text-(--color-text-secondary)">{{ $history->reason }}</p>
                            </div>
                        @empty
                            <p class="text-sm text-(--color-text-secondary)">No reassignment history yet.</p>
                        @endforelse
                    </div>
                </section>
            </aside>
        </div>
    </div>
</x-layouts.app>
