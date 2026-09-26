<x-layouts.app :title="'Workflow Tasks - EAES'">
    <div class="space-y-6">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="portal-page-kicker">Institutional approvals</p>
                <h1 class="text-2xl font-display text-(--color-text-primary)">Workflow Tasks</h1>
                <p class="text-sm text-(--color-text-secondary)">Assigned proposal tasks for your current approval authority.</p>
            </div>
        </div>

        <div class="card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Task</th>
                            <th>Proposal</th>
                            <th>Organizer</th>
                            <th>Status</th>
                            <th>Available</th>
                            <th class="text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($tasks as $task)
                            @php($event = $task->workflowRun->event)
                            <tr>
                                <td data-label="Task">
                                    <div class="font-semibold text-(--color-text-primary)">{{ $task->title }}</div>
                                    <div class="text-xs text-(--color-text-tertiary)">{{ str($task->assigned_role ?: 'approver')->replace('_', ' ')->title() }}</div>
                                </td>
                                <td data-label="Proposal">
                                    <div class="font-medium text-(--color-text-primary)">{{ $event->title }}</div>
                                    <div class="text-xs text-(--color-text-tertiary)">{{ $event->proposal_category ?: 'Activity' }}</div>
                                </td>
                                <td data-label="Organizer">{{ $event->organization?->acronym ?? $event->organization?->name ?? 'N/A' }}</td>
                                <td data-label="Status"><span class="badge badge-pending">{{ str($task->status)->replace('_', ' ')->title() }}</span></td>
                                <td data-label="Available">{{ $task->available_at?->format('M d, Y g:i A') ?? 'After prerequisites' }}</td>
                                <td data-label="Action" class="text-right">
                                    <a class="btn btn-sm btn-secondary" href="{{ route('dashboard.workflow-tasks.show', $task) }}">Open</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6">
                                    <div class="empty-state">
                                        <p class="empty-state-title">No active workflow tasks</p>
                                        <p class="empty-state-description">Assigned proposal approvals will appear here when a workflow reaches your step.</p>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($tasks->hasPages())
                <div class="border-t border-(--color-border) p-4">{{ $tasks->links() }}</div>
            @endif
        </div>
    </div>
</x-layouts.app>
