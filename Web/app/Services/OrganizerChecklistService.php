<?php

namespace App\Services;

use App\Models\ApprovalTrail;
use App\Models\Event;
use App\Models\ProposalWorkflowTask;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class OrganizerChecklistService
{
    /**
     * @return array{
     *     event: Event,
     *     packet: mixed,
     *     workflowRun: mixed,
     *     tasks: Collection<int, ProposalWorkflowTask>,
     *     taskSummaries: Collection<string, array<string, mixed>>,
     *     actionableTasks: Collection<int, ProposalWorkflowTask>,
     *     returnedTasks: Collection<int, ProposalWorkflowTask>,
     *     latestDecision: ApprovalTrail|null,
     *     nextExpectedAction: array<string, mixed>,
     *     focusedTask: ProposalWorkflowTask|null,
     *     organizerScope: mixed,
     *     canEditPacket: bool,
     *     packetEditUrl: string
     * }
     */
    public function viewData(Event $event, User $user, ?string $focusTaskId = null): array
    {
        $event->loadMissing([
            'canonicalPpaPacket',
            'organization',
            'venue',
            'organizerParticipationScope',
            'workflowRun.approvalTrails.actor',
            'workflowRun.tasks.assignedUser',
            'workflowRun.tasks.processStep.dependencies.prerequisiteStep',
            'workflowRun.tasks.approvalTrails.actor',
        ]);

        /** @var Collection<int, ProposalWorkflowTask> $tasks */
        $tasks = ($event->workflowRun?->tasks ?? collect())->sortBy('display_order')->values();
        $tasksByStep = $tasks->keyBy('process_step_id');
        $returnedTasks = $tasks
            ->where('status', ProposalWorkflowTask::STATUS_REVISION_REQUESTED)
            ->values();
        $actionableTasks = $tasks
            ->where('status', ProposalWorkflowTask::STATUS_AVAILABLE)
            ->values();
        $focusedTask = filled($focusTaskId) ? $tasks->firstWhere('id', $focusTaskId) : null;
        $latestDecision = $event->workflowRun?->approvalTrails
            ->sortByDesc(fn (ApprovalTrail $trail): int => $trail->decided_at?->getTimestamp() ?? 0)
            ->first();

        return [
            'event' => $event,
            'packet' => $event->canonicalPpaPacket,
            'workflowRun' => $event->workflowRun,
            'tasks' => $tasks,
            'taskSummaries' => $tasks->mapWithKeys(fn (ProposalWorkflowTask $task): array => [
                $task->id => $this->taskSummary($task, $tasksByStep),
            ]),
            'actionableTasks' => $actionableTasks,
            'returnedTasks' => $returnedTasks,
            'latestDecision' => $latestDecision,
            'nextExpectedAction' => $this->nextExpectedAction($tasks, $actionableTasks, $returnedTasks),
            'focusedTask' => $focusedTask,
            'organizerScope' => $event->organizerParticipationScope,
            'canEditPacket' => $user->can('create-proposals')
                && ! $user->hasRole('Super Admin (OSA)')
                && in_array($event->status, [Event::STATUS_DRAFT, Event::STATUS_REVISION_REQUESTED], true),
            'packetEditUrl' => route('dashboard.events', [
                'status' => Event::STATUS_REVISION_REQUESTED,
                'modal' => 'proposal-edit',
                'event' => $event,
            ]),
        ];
    }

    /**
     * @param  Collection<string, ProposalWorkflowTask>  $tasksByStep
     * @return array<string, mixed>
     */
    private function taskSummary(ProposalWorkflowTask $task, Collection $tasksByStep): array
    {
        $revisionRequestedAt = data_get($task->metadata, 'revision.requested_at');
        $elapsedSince = $task->status === ProposalWorkflowTask::STATUS_REVISION_REQUESTED
            ? ($revisionRequestedAt ? Carbon::parse($revisionRequestedAt) : $task->decided_at)
            : $task->available_at;

        return [
            'assignee' => $this->assignee($task),
            'blockers' => $task->status === ProposalWorkflowTask::STATUS_BLOCKED
                ? $this->blockers($task, $tasksByStep)
                : collect(),
            'elapsed_label' => $elapsedSince instanceof CarbonInterface
                ? $elapsedSince->diffForHumans(now(), CarbonInterface::DIFF_ABSOLUTE)
                : null,
            'elapsed_since' => $elapsedSince,
        ];
    }

    /**
     * @return array{name: string, role: string}
     */
    private function assignee(ProposalWorkflowTask $task): array
    {
        $role = str($task->assigned_role ?: 'institutional approver')->replace('_', ' ')->title()->toString();
        $scope = filled($task->assigned_scope)
            ? ' · '.str($task->assigned_scope)->replace('_', ' ')->title()->toString()
            : '';

        return [
            'name' => $task->assignedUser?->name ?? $role,
            'role' => $role.$scope,
        ];
    }

    /**
     * @param  Collection<string, ProposalWorkflowTask>  $tasksByStep
     * @return Collection<int, string>
     */
    private function blockers(ProposalWorkflowTask $task, Collection $tasksByStep): Collection
    {
        $blockers = collect($task->processStep?->dependencies)
            ->map(function ($dependency) use ($tasksByStep): ?string {
                $prerequisite = $tasksByStep->get($dependency->prerequisite_step_id);

                if ($prerequisite && in_array($prerequisite->status, [
                    ProposalWorkflowTask::STATUS_APPROVED,
                    ProposalWorkflowTask::STATUS_NOT_REQUIRED,
                ], true)) {
                    return null;
                }

                $title = $prerequisite?->title ?? $dependency->prerequisiteStep?->title ?? 'an unresolved prerequisite';
                $status = $prerequisite ? $this->statusLabel($prerequisite->status) : 'not yet created';

                return "Waiting for {$title} ({$status}).";
            })
            ->filter()
            ->values();

        return $blockers->isNotEmpty()
            ? $blockers
            : collect(['Waiting for the workflow to make this step available.']);
    }

    /**
     * @param  Collection<int, ProposalWorkflowTask>  $tasks
     * @param  Collection<int, ProposalWorkflowTask>  $actionableTasks
     * @param  Collection<int, ProposalWorkflowTask>  $returnedTasks
     * @return array{kind: string, heading: string, description: string, tasks: Collection<int, ProposalWorkflowTask>}
     */
    private function nextExpectedAction(Collection $tasks, Collection $actionableTasks, Collection $returnedTasks): array
    {
        if ($returnedTasks->isNotEmpty()) {
            return [
                'kind' => 'organizer_correction',
                'heading' => 'Organizer correction required',
                'description' => 'Correct the returned packet items, then confirm the corrections when resubmitting.',
                'tasks' => $returnedTasks,
            ];
        }

        if ($actionableTasks->isNotEmpty()) {
            return [
                'kind' => 'institutional_action',
                'heading' => 'Institutional action in progress',
                'description' => 'These reviewers can act now. Multiple available steps may be actionable at the same time.',
                'tasks' => $actionableTasks,
            ];
        }

        $rejectedTasks = $tasks->where('status', ProposalWorkflowTask::STATUS_REJECTED)->values();
        if ($rejectedTasks->isNotEmpty()) {
            return [
                'kind' => 'rejected',
                'heading' => 'Proposal rejected',
                'description' => 'Review the recorded decision and remarks below.',
                'tasks' => $rejectedTasks,
            ];
        }

        $pausedTasks = $tasks->where('status', ProposalWorkflowTask::STATUS_PAUSED)->values();
        if ($pausedTasks->isNotEmpty()) {
            return [
                'kind' => 'paused',
                'heading' => 'Workflow paused',
                'description' => 'This step is visible but unavailable for review while it remains paused.',
                'tasks' => $pausedTasks,
            ];
        }

        $blockedTasks = $tasks->where('status', ProposalWorkflowTask::STATUS_BLOCKED)->values();
        if ($blockedTasks->isNotEmpty()) {
            return [
                'kind' => 'blocked',
                'heading' => 'Waiting for prerequisites',
                'description' => 'The blocked steps name the prerequisite that still prevents action.',
                'tasks' => $blockedTasks,
            ];
        }

        return [
            'kind' => 'complete',
            'heading' => 'Workflow complete',
            'description' => 'Every workflow step has reached a terminal state.',
            'tasks' => collect(),
        ];
    }

    private function statusLabel(?string $status): string
    {
        return str($status ?: 'not_started')->replace('_', ' ')->title()->toString();
    }
}
