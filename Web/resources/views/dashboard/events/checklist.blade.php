<x-layouts.app :title="'Organizer Checklist - '.$event->title.' - EAES'">
    @php
        $taskStatusLabel = function (?string $status): string {
            return match ($status) {
                \App\Models\ProposalWorkflowTask::STATUS_BLOCKED => 'Blocked',
                \App\Models\ProposalWorkflowTask::STATUS_AVAILABLE => 'Available',
                \App\Models\ProposalWorkflowTask::STATUS_IN_PROGRESS => 'Pending',
                \App\Models\ProposalWorkflowTask::STATUS_APPROVED => 'Approved',
                \App\Models\ProposalWorkflowTask::STATUS_REJECTED => 'Rejected',
                \App\Models\ProposalWorkflowTask::STATUS_REVISION_REQUESTED => 'Revision Requested',
                \App\Models\ProposalWorkflowTask::STATUS_NOT_REQUIRED => 'Not Required',
                default => filled($status) ? str($status)->replace('_', ' ')->title()->toString() : 'Not Started',
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
        $satisfiedStatuses = [
            \App\Models\ProposalWorkflowTask::STATUS_APPROVED,
            \App\Models\ProposalWorkflowTask::STATUS_NOT_REQUIRED,
        ];
        $returnedTasks = $tasks->where('status', \App\Models\ProposalWorkflowTask::STATUS_REVISION_REQUESTED)->values();
        $openGates = $tasks
            ->filter(fn ($task) => ! in_array($task->status, $satisfiedStatuses, true))
            ->values();
    @endphp

    <div class="space-y-6">
        <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
            <div>
                <p class="portal-page-kicker">Proposal workspace</p>
                <h1 class="text-2xl font-display text-(--color-text-primary)">Organizer Checklist</h1>
                <p class="mt-1 max-w-3xl text-sm text-(--color-text-secondary)">{{ $event->title }} follows the same Workflow Run until every returned task is corrected and resubmitted.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a class="btn btn-sm btn-secondary" href="{{ route('dashboard.events') }}">Back to proposals</a>
                @if($event->canonicalPpaPacket)
                    <a class="btn btn-sm btn-secondary" href="{{ route('dashboard.events.proposal-preview', $event) }}" target="_blank" rel="noopener">Proposal preview</a>
                @endif
            </div>
        </div>

        @if(session('success'))
            <div class="alert alert-success">{{ session('success') }}</div>
        @endif

        @if(session('error'))
            <div class="alert alert-error">{{ session('error') }}</div>
        @endif

        @unless($workflowRun)
            <div class="empty-state py-10">
                <p class="empty-state-title">No workflow run yet</p>
                <p class="empty-state-description">Submit the completed proposal packet to generate the institutional approval checklist.</p>
            </div>
        @else
            <div class="grid gap-5 lg:grid-cols-[minmax(18rem,24rem)_1fr]">
                <aside class="card p-4">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <h2 class="font-semibold text-(--color-text-primary)">Process Rail</h2>
                            <p class="text-xs text-(--color-text-tertiary)">Run {{ str($workflowRun->status)->replace('_', ' ')->title() }}</p>
                        </div>
                        <span class="rounded-full border px-2 py-1 text-xs font-semibold {{ $taskStatusClass($currentTask?->status) }}">
                            {{ $taskStatusLabel($currentTask?->status) }}
                        </span>
                    </div>

                    <ol class="mt-4 space-y-0">
                        @foreach($tasks as $task)
                            @php($isCurrent = $currentTask?->id === $task->id)
                            <li class="relative grid grid-cols-[2rem_1fr] gap-3 pb-5 last:pb-0">
                                @if(! $loop->last)
                                    <span class="absolute left-4 top-8 h-[calc(100%-2rem)] w-px bg-slate-200" aria-hidden="true"></span>
                                @endif
                                <span class="relative z-10 flex h-8 w-8 items-center justify-center rounded-full border text-sm font-semibold {{ $taskStatusClass($task->status) }}">
                                    {{ $task->display_order }}
                                </span>
                                <div class="{{ $isCurrent ? 'rounded-md border border-sky-200 bg-sky-50/70 p-3' : 'pt-1' }}">
                                    <div class="flex flex-col gap-1 sm:flex-row sm:items-start sm:justify-between">
                                        <div>
                                            <p class="text-sm font-semibold text-(--color-text-primary)">{{ $task->title }}</p>
                                            <p class="text-xs text-(--color-text-tertiary)">{{ $task->assignedUser?->name ?? 'Unassigned approver' }}</p>
                                        </div>
                                        <span class="w-fit rounded-full border px-2 py-0.5 text-xs font-semibold {{ $taskStatusClass($task->status) }}">
                                            {{ $taskStatusLabel($task->status) }}
                                        </span>
                                    </div>
                                </div>
                            </li>
                        @endforeach
                    </ol>
                </aside>

                <main class="space-y-5">
                    <section class="card p-5">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <p class="text-xs font-semibold uppercase text-(--color-text-tertiary)">Current action</p>
                                <h2 class="mt-1 text-xl font-semibold text-(--color-text-primary)">{{ $currentTask?->title ?? 'Workflow complete' }}</h2>
                                <p class="mt-1 text-sm text-(--color-text-secondary)">{{ $currentTask?->description ?: 'All recorded workflow tasks have reached a terminal state.' }}</p>
                            </div>
                            @if($currentTask)
                                <span class="rounded-full border px-2 py-1 text-xs font-semibold {{ $taskStatusClass($currentTask->status) }}">
                                    {{ $taskStatusLabel($currentTask->status) }}
                                </span>
                            @endif
                        </div>

                        <dl class="mt-5 grid gap-3 sm:grid-cols-2">
                            <div>
                                <dt class="proposal-detail-label">Approver</dt>
                                <dd class="proposal-detail-value">{{ $currentTask?->assignedUser?->name ?? 'Unassigned' }}</dd>
                            </div>
                            <div>
                                <dt class="proposal-detail-label">Packet status</dt>
                                <dd class="proposal-detail-value">{{ str($event->status)->replace('_', ' ')->title() }}</dd>
                            </div>
                            <div>
                                <dt class="proposal-detail-label">PPA category</dt>
                                <dd class="proposal-detail-value">{{ ucfirst($packet?->proposal_category ?? $event->proposal_category ?? 'activity') }}</dd>
                            </div>
                            <div>
                                <dt class="proposal-detail-label">Venue / platform</dt>
                                <dd class="proposal-detail-value">{{ $event->venue?->displayName() ?? $packet?->location_details ?? $event->location_details ?? 'N/A' }}</dd>
                            </div>
                        </dl>

                        @if($currentTask?->remarks)
                            <div class="mt-5 rounded-md border border-rose-200 bg-rose-50 p-4">
                                <p class="text-xs font-semibold uppercase text-rose-700">Approver remarks</p>
                                <p class="mt-1 text-sm text-rose-900">{{ $currentTask->remarks }}</p>
                            </div>
                        @elseif($organizerScope?->review_remarks)
                            <div class="mt-5 rounded-md border border-amber-200 bg-amber-50 p-4">
                                <p class="text-xs font-semibold uppercase text-amber-700">Review remarks</p>
                                <p class="mt-1 text-sm text-amber-900">{{ $organizerScope->review_remarks }}</p>
                            </div>
                        @endif

                        <div class="mt-5 rounded-md border border-slate-200 bg-slate-50 p-4">
                            <p class="text-xs font-semibold uppercase text-slate-600">Unmet gates</p>
                            @if($openGates->isEmpty())
                                <p class="mt-1 text-sm text-slate-700">Every required checklist task is satisfied.</p>
                            @else
                                <ul class="mt-2 space-y-1 text-sm text-slate-700">
                                    @foreach($openGates as $gate)
                                        <li>{{ $gate->title }} - {{ $taskStatusLabel($gate->status) }}</li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                    </section>

                    <section class="card p-5">
                        <h2 class="font-semibold text-(--color-text-primary)">Packet Actions</h2>
                        <div class="mt-4 flex flex-wrap gap-2">
                            @if($canEditPacket)
                                <a class="btn btn-secondary" href="{{ $packetEditUrl }}">Edit returned packet</a>
                            @endif

                            @if($event->status === \App\Models\Event::STATUS_REVISION_REQUESTED && $returnedTasks->isNotEmpty())
                                <form method="POST" action="{{ route('dashboard.events.submit', $event) }}" class="space-y-3">
                                    @csrf
                                    <label class="flex max-w-xl items-start gap-2 text-sm text-(--color-text-secondary)">
                                        <input type="checkbox" name="returned_tasks_addressed" value="1" class="mt-1" required>
                                        <span>Returned tasks and packet corrections have been addressed.</span>
                                    </label>
                                    <button type="submit" class="btn btn-primary">Resubmit proposal</button>
                                </form>
                            @elseif($event->status === \App\Models\Event::STATUS_DRAFT)
                                <form method="POST" action="{{ route('dashboard.events.submit', $event) }}">
                                    @csrf
                                    <button type="submit" class="btn btn-primary">Submit proposal</button>
                                </form>
                            @endif
                        </div>
                        @if($returnedTasks->isNotEmpty())
                            <p class="mt-3 text-xs text-(--color-text-tertiary)">Resubmission reopens returned tasks on this same Workflow Run after the packet has been edited.</p>
                        @endif
                    </section>

                    <section class="card p-5">
                        <h2 class="font-semibold text-(--color-text-primary)">Approval Trail</h2>
                        <div class="mt-4 space-y-3">
                            @forelse($workflowRun->approvalTrails as $trail)
                                <div class="rounded-md border border-slate-200 p-3">
                                    <div class="flex flex-col gap-1 sm:flex-row sm:items-start sm:justify-between">
                                        <div>
                                            <p class="text-sm font-semibold text-(--color-text-primary)">{{ str($trail->decision)->replace('_', ' ')->title() }}</p>
                                            <p class="text-xs text-(--color-text-tertiary)">{{ $trail->actor?->name ?? 'Recorded approver' }} · {{ $trail->decided_at?->format('M d, Y g:i A') }}</p>
                                        </div>
                                        <span class="text-xs text-(--color-text-tertiary)">{{ $trail->reference_id }}</span>
                                    </div>
                                    @if($trail->remarks)
                                        <p class="mt-2 text-sm text-(--color-text-secondary)">{{ $trail->remarks }}</p>
                                    @endif
                                </div>
                            @empty
                                <p class="text-sm text-(--color-text-tertiary)">No approval decisions have been recorded yet.</p>
                            @endforelse
                        </div>
                    </section>
                </main>
            </div>
        @endunless
    </div>
</x-layouts.app>
