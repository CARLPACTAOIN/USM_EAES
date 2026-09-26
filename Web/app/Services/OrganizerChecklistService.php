<?php

namespace App\Services;

use App\Models\Event;
use App\Models\ProposalWorkflowTask;
use App\Models\User;
use Illuminate\Support\Collection;

class OrganizerChecklistService
{
    /**
     * @return array{
     *     event: Event,
     *     packet: mixed,
     *     workflowRun: mixed,
     *     tasks: Collection<int, ProposalWorkflowTask>,
     *     currentTask: ProposalWorkflowTask|null,
     *     organizerScope: mixed,
     *     canEditPacket: bool,
     *     packetEditUrl: string
     * }
     */
    public function viewData(Event $event, User $user): array
    {
        $event->loadMissing([
            'canonicalPpaPacket',
            'organization',
            'venue',
            'organizerParticipationScope',
            'workflowRun.approvalTrails.actor',
            'workflowRun.tasks.assignedUser',
            'workflowRun.tasks.approvalTrails.actor',
        ]);

        $tasks = $event->workflowRun?->tasks ?? collect();
        $currentTask = $tasks->firstWhere('status', ProposalWorkflowTask::STATUS_REVISION_REQUESTED)
            ?? $tasks->firstWhere('status', ProposalWorkflowTask::STATUS_AVAILABLE)
            ?? $tasks->firstWhere('status', ProposalWorkflowTask::STATUS_IN_PROGRESS)
            ?? $tasks->firstWhere('status', ProposalWorkflowTask::STATUS_BLOCKED)
            ?? $tasks->last();

        return [
            'event' => $event,
            'packet' => $event->canonicalPpaPacket,
            'workflowRun' => $event->workflowRun,
            'tasks' => $tasks,
            'currentTask' => $currentTask,
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
}
