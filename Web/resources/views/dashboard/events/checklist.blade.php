<x-layouts.app :title="'Organizer Checklist - '.$event->title.' - EAES'">
    @php
        $taskStatusLabel = function (?string $status): string {
            return match ($status) {
                \App\Models\ProposalWorkflowTask::STATUS_BLOCKED => 'Blocked',
                \App\Models\ProposalWorkflowTask::STATUS_AVAILABLE => 'Available',
                \App\Models\ProposalWorkflowTask::STATUS_IN_PROGRESS => 'In Progress',
                \App\Models\ProposalWorkflowTask::STATUS_PAUSED => 'Paused',
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
                \App\Models\ProposalWorkflowTask::STATUS_PAUSED => 'border-violet-200 bg-violet-50 text-violet-700',
                \App\Models\ProposalWorkflowTask::STATUS_REVISION_REQUESTED => 'border-rose-200 bg-rose-50 text-rose-700',
                \App\Models\ProposalWorkflowTask::STATUS_REJECTED => 'border-red-200 bg-red-50 text-red-700',
                default => 'border-slate-200 bg-slate-50 text-slate-600',
            };
        };
        $satisfiedStatuses = [
            \App\Models\ProposalWorkflowTask::STATUS_APPROVED,
            \App\Models\ProposalWorkflowTask::STATUS_NOT_REQUIRED,
        ];
        $targetPreviewUrl = function (array $target) use ($event): string {
            return route('dashboard.events.proposal-preview', $event).'#'.($target['anchor'] ?? 'packet-content');
        };
        $openGates = $tasks
            ->filter(fn ($task) => ! in_array($task->status, $satisfiedStatuses, true))
            ->values();
    @endphp

    <div class="space-y-6">
        <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
            <div>
                <p class="portal-page-kicker">Proposal workspace</p>
                <h1 class="text-2xl font-display text-(--color-text-primary)">Organizer Checklist</h1>
                <p class="mt-1 max-w-3xl text-sm text-(--color-text-secondary)">Every process step remains visible while this Workflow Run moves through review, revision, and permit approval.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a class="btn btn-sm btn-secondary" href="{{ route('dashboard.events') }}">Back to proposals</a>
                @if($event->canonicalPpaPacket)
                    <a class="btn btn-sm btn-secondary" href="{{ route('dashboard.events.proposal-preview', $event) }}" target="_blank" rel="noopener">Proposal preview</a>
                    <a class="btn btn-sm btn-secondary" href="{{ route('dashboard.events.permit-preview', $event) }}" target="_blank" rel="noopener">Permit preview</a>
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
            <div class="grid gap-5 xl:grid-cols-[minmax(19rem,25rem)_minmax(0,1fr)]">
                <aside class="card p-4" aria-labelledby="process-rail-heading">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <h2 id="process-rail-heading" class="font-semibold text-(--color-text-primary)">Process Rail</h2>
                            <p class="text-xs text-(--color-text-tertiary)">Run {{ str($workflowRun->status)->replace('_', ' ')->title() }}</p>
                        </div>
                        <span class="rounded-full border px-2 py-1 text-xs font-semibold {{ $taskStatusClass($nextExpectedAction['tasks']->first()?->status) }}">
                            {{ $nextExpectedAction['heading'] }}
                        </span>
                    </div>

                    <nav class="mt-4" aria-label="Complete proposal workflow">
                        <ol class="space-y-0">
                            @foreach($tasks as $task)
                                @php
                                    $summary = $taskSummaries->get($task->id, []);
                                    $isFocused = $focusedTask?->id === $task->id;
                                @endphp
                                <li id="workflow-task-{{ $task->id }}" tabindex="-1" class="relative grid grid-cols-[2rem_minmax(0,1fr)] gap-3 pb-5 last:pb-0">
                                    @if(! $loop->last)
                                        <span class="absolute left-4 top-8 h-[calc(100%-2rem)] w-px bg-slate-200" aria-hidden="true"></span>
                                    @endif
                                    <span class="relative z-10 flex h-8 w-8 items-center justify-center rounded-full border text-sm font-semibold {{ $taskStatusClass($task->status) }}">
                                        {{ $task->display_order }}
                                    </span>
                                    <article class="min-w-0 rounded-md {{ $isFocused ? 'border-2 border-sky-500 bg-sky-50 p-3 shadow-sm' : 'border border-transparent p-3' }}">
                                        <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                            <div class="min-w-0">
                                                <h3 class="text-sm font-semibold text-(--color-text-primary)">{{ $task->title }}</h3>
                                                <p class="mt-1 text-xs text-(--color-text-secondary)">Assigned to {{ data_get($summary, 'assignee.name') }}</p>
                                                <p class="text-xs text-(--color-text-tertiary)">{{ data_get($summary, 'assignee.role') }}</p>
                                            </div>
                                            <span class="w-fit shrink-0 rounded-full border px-2 py-0.5 text-xs font-semibold {{ $taskStatusClass($task->status) }}">
                                                {{ $taskStatusLabel($task->status) }}
                                            </span>
                                        </div>

                                        @if(data_get($summary, 'elapsed_label'))
                                            <p class="mt-2 text-xs text-(--color-text-tertiary)">Elapsed: {{ data_get($summary, 'elapsed_label') }}</p>
                                        @endif

                                        @if($task->status === \App\Models\ProposalWorkflowTask::STATUS_BLOCKED)
                                            <ul class="mt-2 space-y-1 text-xs text-slate-600" aria-label="Prerequisites blocking {{ $task->title }}">
                                                @foreach(data_get($summary, 'blockers', collect()) as $blocker)
                                                    <li>{{ $blocker }}</li>
                                                @endforeach
                                            </ul>
                                        @endif

                                        @if($task->remarks && in_array($task->status, [\App\Models\ProposalWorkflowTask::STATUS_REJECTED, \App\Models\ProposalWorkflowTask::STATUS_REVISION_REQUESTED], true))
                                            <div class="mt-3 rounded border border-rose-200 bg-rose-50 p-2 text-xs text-rose-900">
                                                <p class="font-semibold">Decision remarks</p>
                                                <p class="mt-1">{{ $task->remarks }}</p>
                                                @if(data_get($task->metadata, 'revision.target.label'))
                                                    @php($target = data_get($task->metadata, 'revision.target'))
                                                    <a class="mt-2 inline-flex font-semibold underline decoration-rose-300 underline-offset-2" href="{{ $targetPreviewUrl($target) }}" target="_blank" rel="noopener">Review target: {{ $target['label'] }}</a>
                                                @endif
                                            </div>
                                        @endif
                                    </article>
                                </li>
                            @endforeach
                        </ol>
                    </nav>
                </aside>

                <main class="space-y-5">
                    <section class="card p-5" aria-labelledby="next-action-heading">
                        <p class="text-xs font-semibold uppercase text-(--color-text-tertiary)">Next expected action</p>
                        <h2 id="next-action-heading" class="mt-1 text-xl font-semibold text-(--color-text-primary)">{{ $nextExpectedAction['heading'] }}</h2>
                        <p class="mt-1 text-sm text-(--color-text-secondary)">{{ $nextExpectedAction['description'] }}</p>

                        @if($nextExpectedAction['tasks']->isNotEmpty())
                            <div class="mt-4 grid gap-3 md:grid-cols-2">
                                @foreach($nextExpectedAction['tasks'] as $task)
                                    @php($summary = $taskSummaries->get($task->id, []))
                                    <a class="rounded-md border border-(--color-border) p-3 text-left transition hover:border-sky-300 focus:outline-none focus:ring-2 focus:ring-sky-500" href="#workflow-task-{{ $task->id }}">
                                        <div class="flex items-start justify-between gap-3">
                                            <span class="text-sm font-semibold text-(--color-text-primary)">{{ $task->title }}</span>
                                            <span class="shrink-0 rounded-full border px-2 py-0.5 text-xs font-semibold {{ $taskStatusClass($task->status) }}">{{ $taskStatusLabel($task->status) }}</span>
                                        </div>
                                        <p class="mt-2 text-xs text-(--color-text-secondary)">{{ data_get($summary, 'assignee.name') }} · {{ data_get($summary, 'assignee.role') }}</p>
                                        @if(data_get($summary, 'elapsed_label'))
                                            <p class="mt-1 text-xs text-(--color-text-tertiary)">Elapsed: {{ data_get($summary, 'elapsed_label') }}</p>
                                        @endif
                                    </a>
                                @endforeach
                            </div>
                        @endif
                    </section>

                    <section class="card p-5" aria-labelledby="proposal-status-heading">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <h2 id="proposal-status-heading" class="font-semibold text-(--color-text-primary)">Proposal status</h2>
                                <p class="mt-1 text-sm text-(--color-text-secondary)">Workflow status is separate from the proposal packet status and event lifecycle.</p>
                            </div>
                            <span class="rounded-full border border-slate-200 bg-slate-50 px-2 py-1 text-xs font-semibold text-slate-700">{{ str($event->status)->replace('_', ' ')->title() }}</span>
                        </div>
                        <dl class="mt-5 grid gap-3 sm:grid-cols-2">
                            <div><dt class="proposal-detail-label">PPA category</dt><dd class="proposal-detail-value">{{ ucfirst($packet?->proposal_category ?? $event->proposal_category ?? 'activity') }}</dd></div>
                            <div><dt class="proposal-detail-label">Venue / platform</dt><dd class="proposal-detail-value">{{ $event->venue?->displayName() ?? $packet?->location_details ?? $event->location_details ?? 'N/A' }}</dd></div>
                        </dl>

                        <div class="mt-5 rounded-md border border-slate-200 bg-slate-50 p-4">
                            <p class="text-xs font-semibold uppercase text-slate-600">Unmet gates</p>
                            @if($openGates->isEmpty())
                                <p class="mt-1 text-sm text-slate-700">Every required checklist task is satisfied.</p>
                            @else
                                <ul class="mt-2 space-y-1 text-sm text-slate-700">
                                    @foreach($openGates as $gate)
                                        <li>{{ $gate->title }} — {{ $taskStatusLabel($gate->status) }}</li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                    </section>

                    @if($latestDecision)
                        <section class="card p-5" aria-labelledby="last-decision-heading">
                            <p class="text-xs font-semibold uppercase text-(--color-text-tertiary)">Last decision</p>
                            <h2 id="last-decision-heading" class="mt-1 font-semibold text-(--color-text-primary)">{{ str($latestDecision->decision)->replace('_', ' ')->title() }}</h2>
                            <p class="mt-1 text-sm text-(--color-text-secondary)">{{ $latestDecision->actor?->name ?? 'Recorded approver' }} · {{ $latestDecision->decided_at?->format('M d, Y g:i A') }}</p>
                            @if($latestDecision->remarks)
                                <p class="mt-3 text-sm text-(--color-text-secondary)">{{ $latestDecision->remarks }}</p>
                            @endif
                        </section>
                    @endif

                    <section class="card p-5" aria-labelledby="packet-actions-heading">
                        <h2 id="packet-actions-heading" class="font-semibold text-(--color-text-primary)">Packet actions</h2>
                        <div class="mt-4 flex flex-wrap gap-2">
                            @if($canEditPacket)
                                <a class="btn btn-secondary" href="{{ $packetEditUrl }}">Edit returned packet</a>
                            @endif

                            @if($event->status === \App\Models\Event::STATUS_REVISION_REQUESTED && $returnedTasks->isNotEmpty())
                                <form method="POST" action="{{ route('dashboard.events.submit', $event) }}" class="space-y-3">
                                    @csrf
                                    @if($returnedTasks->contains(fn ($task) => (bool) data_get($task->metadata, 'revision.target.label')))
                                        <div class="max-w-xl rounded-md border border-rose-100 bg-rose-50/70 p-3 text-sm text-rose-900">
                                            <p class="font-semibold">Targeted corrections</p>
                                            <ul class="mt-2 space-y-1">
                                                @foreach($returnedTasks as $returnedTask)
                                                    @if(data_get($returnedTask->metadata, 'revision.target.label'))
                                                        @php($target = data_get($returnedTask->metadata, 'revision.target'))
                                                        <li><a class="font-medium underline decoration-rose-300 underline-offset-2" href="{{ $targetPreviewUrl($target) }}" target="_blank" rel="noopener">{{ $target['label'] }}</a> <span class="text-rose-700">for {{ $returnedTask->title }}</span></li>
                                                    @endif
                                                @endforeach
                                            </ul>
                                        </div>
                                    @endif
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

                    <section class="card p-5" aria-labelledby="approval-trail-heading">
                        <h2 id="approval-trail-heading" class="font-semibold text-(--color-text-primary)">Approval Trail</h2>
                        <div class="mt-4 space-y-3">
                            @forelse($workflowRun->approvalTrails as $trail)
                                <article id="approval-trail-{{ $trail->id }}" tabindex="-1" class="rounded-md border border-slate-200 p-3">
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
                                    @if(data_get($trail->metadata, 'revision_target.label'))
                                        @php($target = data_get($trail->metadata, 'revision_target'))
                                        <a class="mt-2 inline-flex text-xs font-semibold uppercase text-rose-700 underline decoration-rose-300 underline-offset-2" href="{{ $targetPreviewUrl($target) }}" target="_blank" rel="noopener">Target: {{ $target['label'] }}</a>
                                    @endif
                                </article>
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
