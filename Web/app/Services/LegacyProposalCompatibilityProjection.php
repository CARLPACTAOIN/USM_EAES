<?php

namespace App\Services;

use App\Models\Event;
use App\Models\EventParticipationScope;
use App\Models\ProposalWorkflowRun;
use App\Models\ProposalWorkflowTask;
use Illuminate\Support\Collection;

class LegacyProposalCompatibilityProjection
{
    /**
     * Keep retired organizer columns as display/export projections of workflow decisions.
     */
    public function syncOrganizer(Event $event): void
    {
        $run = ProposalWorkflowRun::query()
            ->with('tasks')
            ->where('event_id', $event->id)
            ->whereNull('event_participation_scope_id')
            ->first();

        $projection = $this->projectionFromRun($run);

        $event->forceFill([
            'hardcopy_submitted' => $projection['approved'],
            'hardcopy_submitted_at' => $projection['approved_at'],
            'head_organization_signed' => $projection['organization_head_signed'],
            'adviser_signed' => $projection['adviser_signed'],
        ])->saveQuietly();

        EventParticipationScope::query()
            ->where('event_id', $event->id)
            ->where('participation_role', EventParticipationScope::ROLE_ORGANIZER)
            ->update([
                'hardcopy_submitted' => $projection['approved'],
                'org_head_signed' => $projection['organization_head_signed'],
                'adviser_signed' => $projection['adviser_signed'],
            ]);
    }

    /**
     * Keep retired participant-PPA columns as display/export projections of workflow decisions.
     */
    public function syncParticipantScope(EventParticipationScope $scope): void
    {
        $run = ProposalWorkflowRun::query()
            ->with('tasks')
            ->where('event_participation_scope_id', $scope->id)
            ->first();

        $projection = $this->projectionFromRun($run);

        $scope->forceFill([
            'hardcopy_submitted' => $projection['approved'],
            'org_head_signed' => $projection['organization_head_signed'],
            'adviser_signed' => $projection['adviser_signed'],
        ])->saveQuietly();
    }

    /**
     * @return array{approved: bool, approved_at: mixed, organization_head_signed: bool, adviser_signed: bool}
     */
    private function projectionFromRun(?ProposalWorkflowRun $run): array
    {
        $tasks = $run?->tasks ?? collect();

        return [
            'approved' => $run?->status === ProposalWorkflowRun::STATUS_APPROVED,
            'approved_at' => $run?->status === ProposalWorkflowRun::STATUS_APPROVED ? $run->completed_at : null,
            'organization_head_signed' => $this->roleApproved($tasks, 'organization_head'),
            'adviser_signed' => $this->roleApproved($tasks, 'adviser'),
        ];
    }

    /**
     * @param  Collection<int, ProposalWorkflowTask>  $tasks
     */
    private function roleApproved(Collection $tasks, string $role): bool
    {
        return $tasks->contains(
            fn (ProposalWorkflowTask $task): bool => $task->assigned_role === $role
                && in_array($task->status, [
                    ProposalWorkflowTask::STATUS_APPROVED,
                    ProposalWorkflowTask::STATUS_NOT_REQUIRED,
                ], true)
        );
    }
}
