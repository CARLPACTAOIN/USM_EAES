<?php

namespace App\Services;

use App\Models\AdminAssignment;
use App\Models\ApprovalTrail;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\ProposalProcessStepDependency;
use App\Models\ProposalWorkflowRun;
use App\Models\ProposalWorkflowTask;
use App\Models\User;
use App\Models\WorkflowTaskAssignmentHistory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProposalWorkflowTaskService
{
    private const ACTIVE_STATUSES = [
        ProposalWorkflowTask::STATUS_BLOCKED,
        ProposalWorkflowTask::STATUS_AVAILABLE,
        ProposalWorkflowTask::STATUS_IN_PROGRESS,
    ];

    private const TERMINAL_STATUSES = [
        ProposalWorkflowTask::STATUS_APPROVED,
        ProposalWorkflowTask::STATUS_REJECTED,
        ProposalWorkflowTask::STATUS_REVISION_REQUESTED,
        ProposalWorkflowTask::STATUS_NOT_REQUIRED,
    ];

    private const SATISFIED_PREREQUISITE_STATUSES = [
        ProposalWorkflowTask::STATUS_APPROVED,
        ProposalWorkflowTask::STATUS_NOT_REQUIRED,
    ];

    private const CANONICAL_DECISIONS = [
        ProposalWorkflowTask::DECISION_APPROVED,
        ProposalWorkflowTask::DECISION_REJECTED,
        ProposalWorkflowTask::DECISION_REVISION_REQUESTED,
        ProposalWorkflowTask::DECISION_NOT_REQUIRED,
    ];

    /**
     * @return LengthAwarePaginator<int, ProposalWorkflowTask>
     */
    public function inboxFor(User $user, int $perPage = 15): LengthAwarePaginator
    {
        $tasks = ProposalWorkflowTask::query()
            ->with(['workflowRun.event.organization', 'assignedUser', 'processStep'])
            ->where('assigned_user_id', $user->id)
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->latest('available_at')
            ->orderBy('display_order')
            ->get()
            ->filter(fn (ProposalWorkflowTask $task): bool => $this->userCanViewTask($user, $task))
            ->values();

        $page = Paginator::resolveCurrentPage();
        $items = $tasks->forPage($page, $perPage)->values();

        return new Paginator($items, $tasks->count(), $perPage, $page, [
            'path' => Paginator::resolveCurrentPath(),
        ]);
    }

    public function taskForUserOrFail(User $user, ProposalWorkflowTask $task): ProposalWorkflowTask
    {
        $task->load($this->detailRelations());

        abort_unless(
            $this->userCanViewTask($user, $task)
                || $this->userCanViewCompletedTask($user, $task)
                || $this->userCanAdministerTask($user, $task),
            403
        );

        return $task;
    }

    /**
     * @return Collection<int, User>
     */
    public function eligibleReplacements(ProposalWorkflowTask $task): Collection
    {
        $task->loadMissing('workflowRun.event');
        $roleKey = $task->assigned_role;
        $scope = data_get($task->metadata, 'assignment.scope', []);

        return match ($roleKey) {
            'osa' => User::role('Super Admin (OSA)')->orderBy('name')->get(),
            'chief_security' => User::role('Chief Security')->orderBy('name')->get(),
            'dept_chair' => $this->usersWithAdminAssignment('Department Chairperson', $scope),
            'college_dean' => $this->usersWithAdminAssignment('College Dean', $scope),
            'venue_admin' => $this->usersWithAdminAssignment('Venue Custodian', $scope),
            default => $this->usersWithAdminAssignment($this->roleLabel($roleKey), $scope),
        };
    }

    public function reassign(ProposalWorkflowTask $task, User $replacement, User $actor, string $reason): WorkflowTaskAssignmentHistory
    {
        if (! $actor->hasRole('Super Admin (OSA)') || ! $actor->can('approve-proposals')) {
            abort(403, 'Only OSA can reassign workflow tasks.');
        }

        if (! in_array($task->status, self::ACTIVE_STATUSES, true)) {
            throw ValidationException::withMessages([
                'task' => 'Only active workflow tasks can be reassigned.',
            ]);
        }

        if (! $this->eligibleReplacements($task)->contains('id', $replacement->id)) {
            throw ValidationException::withMessages([
                'new_assignee_id' => 'The selected replacement is not eligible for this workflow task.',
            ]);
        }

        if ($task->assigned_user_id === $replacement->id) {
            throw ValidationException::withMessages([
                'new_assignee_id' => 'The selected replacement is already assigned to this task.',
            ]);
        }

        return DB::transaction(function () use ($task, $replacement, $actor, $reason): WorkflowTaskAssignmentHistory {
            $lockedTask = ProposalWorkflowTask::with('workflowRun')
                ->whereKey($task->id)
                ->lockForUpdate()
                ->firstOrFail();
            $formerAssigneeId = $lockedTask->assigned_user_id;
            $metadata = $lockedTask->metadata ?? [];

            data_set($metadata, 'assignment.assigned_user_snapshot', [
                'id' => $replacement->id,
                'name' => $replacement->name,
                'email' => $replacement->email,
            ]);
            data_set($metadata, 'assignment.reassigned_by', [
                'id' => $actor->id,
                'name' => $actor->name,
            ]);
            data_set($metadata, 'assignment.reassigned_at', now()->toIso8601String());

            $history = WorkflowTaskAssignmentHistory::create([
                'workflow_task_id' => $lockedTask->id,
                'former_assignee_id' => $formerAssigneeId,
                'new_assignee_id' => $replacement->id,
                'actor_id' => $actor->id,
                'reason' => $reason,
                'reassigned_at' => now(),
                'metadata' => [
                    'assigned_role' => $lockedTask->assigned_role,
                    'assigned_scope' => $lockedTask->assigned_scope,
                    'event_id' => $lockedTask->workflowRun?->event_id,
                ],
            ]);

            $lockedTask->update([
                'assigned_user_id' => $replacement->id,
                'metadata' => $metadata,
            ]);

            return $history;
        });
    }

    public function decide(ProposalWorkflowTask $task, User $actor, string $decision, ?string $remarks = null): ApprovalTrail
    {
        $remarks = filled($remarks) ? trim((string) $remarks) : null;

        $this->validateDecisionInput($task, $actor, $decision, $remarks);

        return DB::transaction(function () use ($task, $actor, $decision, $remarks): ApprovalTrail {
            $lockedTask = ProposalWorkflowTask::with(['workflowRun.event', 'processStep'])
                ->whereKey($task->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->validateDecisionInput($lockedTask, $actor, $decision, $remarks);

            $previousStatus = $lockedTask->status;
            $resultingStatus = $this->statusForDecision($decision);
            $decidedAt = now();
            $event = $lockedTask->workflowRun->event;

            $lockedTask->update([
                'status' => $resultingStatus,
                'decision' => $decision,
                'remarks' => $remarks,
                'decided_by' => $actor->id,
                'decided_at' => $decidedAt,
                'completed_at' => $decidedAt,
            ]);

            $trail = ApprovalTrail::create([
                'proposal_id' => $event->id,
                'workflow_run_id' => $lockedTask->workflow_run_id,
                'workflow_task_id' => $lockedTask->id,
                'actor_id' => $actor->id,
                'decision' => $decision,
                'remarks' => $remarks,
                'reference_id' => $this->referenceId($lockedTask),
                'previous_status' => $previousStatus,
                'resulting_status' => $resultingStatus,
                'assigned_role' => $lockedTask->assigned_role,
                'assigned_scope' => $lockedTask->assigned_scope,
                'decided_at' => $decidedAt,
                'metadata' => [
                    'actor' => [
                        'id' => $actor->id,
                        'name' => $actor->name,
                        'email' => $actor->email,
                    ],
                    'assignment' => data_get($lockedTask->metadata, 'assignment', []),
                    'event_status_before' => $event->status,
                    'workflow_run_status_before' => $lockedTask->workflowRun->status,
                ],
            ]);

            AuditLog::create([
                'target_id' => $lockedTask->id,
                'admin_id' => $actor->id,
                'action' => 'workflow-task-decision',
                'details' => [
                    'proposal_id' => $event->id,
                    'workflow_run_id' => $lockedTask->workflow_run_id,
                    'decision' => $decision,
                    'previous_status' => $previousStatus,
                    'resulting_status' => $resultingStatus,
                    'approval_trail_id' => $trail->id,
                    'reference_id' => $trail->reference_id,
                ],
            ]);

            if (in_array($resultingStatus, self::SATISFIED_PREREQUISITE_STATUSES, true)) {
                $this->unblockSatisfiedDependents($lockedTask, $decidedAt);
                $this->markRunApprovedWhenComplete($lockedTask->workflowRun, $decidedAt);
            } elseif ($resultingStatus === ProposalWorkflowTask::STATUS_REJECTED) {
                $this->markRunAndProposalRejected($lockedTask->workflowRun, $event, $decidedAt);
            } elseif ($resultingStatus === ProposalWorkflowTask::STATUS_REVISION_REQUESTED) {
                $this->markRunAndProposalRevisionRequested($lockedTask->workflowRun, $event);
            }

            return $trail;
        });
    }

    public function userCanViewTask(User $user, ProposalWorkflowTask $task): bool
    {
        return $this->userCanViewTaskWithStatuses($user, $task, self::ACTIVE_STATUSES);
    }

    private function userCanViewCompletedTask(User $user, ProposalWorkflowTask $task): bool
    {
        return $this->userCanViewTaskWithStatuses($user, $task, self::TERMINAL_STATUSES);
    }

    /**
     * @param  array<int, string>  $statuses
     */
    private function userCanViewTaskWithStatuses(User $user, ProposalWorkflowTask $task, array $statuses): bool
    {
        if ($task->assigned_user_id !== $user->id) {
            return false;
        }

        if (! in_array($task->status, $statuses, true)) {
            return false;
        }

        $roleKey = (string) $task->assigned_role;

        if (in_array($roleKey, ['dept_chair', 'college_dean', 'chief_security', 'venue_admin', 'osa'], true)) {
            return $this->hasMatchingInstitutionalAssignment($user, $task);
        }

        return true;
    }

    private function userCanAdministerTask(User $user, ProposalWorkflowTask $task): bool
    {
        return $user->hasRole('Super Admin (OSA)')
            && $user->can('approve-proposals')
            && in_array($task->status, self::ACTIVE_STATUSES, true);
    }

    /**
     * @return array<int, string>
     */
    private function detailRelations(): array
    {
        return [
            'assignedUser',
            'processStep.dependencies.prerequisiteStep',
            'approvalTrails.actor',
            'assignmentHistory.formerAssignee',
            'assignmentHistory.newAssignee',
            'assignmentHistory.actor',
            'workflowRun.approvalTrails.actor',
            'workflowRun.tasks.assignedUser',
            'workflowRun.event.canonicalPpaPacket',
            'workflowRun.event.organization',
            'workflowRun.event.venue',
            'workflowRun.event.permitVersions.approver',
        ];
    }

    private function hasMatchingInstitutionalAssignment(User $user, ProposalWorkflowTask $task): bool
    {
        $roleName = $this->roleNameForTask($task);

        if ($roleName === 'Super Admin (OSA)') {
            return $user->hasRole('Super Admin (OSA)');
        }

        if ($roleName === 'Chief Security') {
            return $user->hasRole('Chief Security');
        }

        $scope = data_get($task->metadata, 'assignment.scope', []);

        return AdminAssignment::query()
            ->where('user_id', $user->id)
            ->where('role_name', $roleName)
            ->currentlyEffective()
            ->where(function (Builder $query) use ($roleName, $scope): void {
                $this->scopeAssignmentQuery($query, $scope, $roleName);
            })
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $scope
     * @return Collection<int, User>
     */
    private function usersWithAdminAssignment(string $roleName, array $scope): Collection
    {
        return User::query()
            ->whereHas('adminAssignments', function (Builder $query) use ($roleName, $scope): void {
                $query->where('role_name', $roleName)
                    ->currentlyEffective();

                $this->scopeAssignmentQuery($query, $scope, $roleName);
            })
            ->orderBy('name')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $scope
     */
    private function scopeAssignmentQuery(Builder $query, array $scope, string $roleName): void
    {
        $schoolYearId = $scope['school_year_id'] ?? null;
        $organizationId = $scope['organization_id'] ?? null;
        $departmentId = $scope['department_id'] ?? null;
        $collegeId = $scope['college_id'] ?? null;

        $query->where(function (Builder $scopeQuery) use ($schoolYearId): void {
            $scopeQuery->whereNull('school_year_id');

            if ($schoolYearId) {
                $scopeQuery->orWhere('school_year_id', $schoolYearId);
            }
        });

        if ($roleName === 'Department Chairperson' && $departmentId) {
            $query->where('department_id', $departmentId);

            return;
        }

        if ($roleName === 'College Dean' && $collegeId) {
            $query->where('college_id', $collegeId);

            return;
        }

        if ($organizationId) {
            $query->where('organization_id', $organizationId);
        }
    }

    private function roleNameForTask(ProposalWorkflowTask $task): string
    {
        return match ($task->assigned_role) {
            'dept_chair' => 'Department Chairperson',
            'college_dean' => 'College Dean',
            'chief_security' => 'Chief Security',
            'osa' => 'Super Admin (OSA)',
            'venue_admin' => 'Venue Custodian',
            default => $this->roleLabel($task->assigned_role),
        };
    }

    private function roleLabel(?string $roleKey): string
    {
        return filled($roleKey)
            ? str((string) $roleKey)->replace('_', ' ')->title()->toString()
            : 'Institutional Approver';
    }

    private function validateDecisionInput(ProposalWorkflowTask $task, User $actor, string $decision, ?string $remarks): void
    {
        if (! in_array($decision, self::CANONICAL_DECISIONS, true)) {
            throw ValidationException::withMessages([
                'decision' => 'Select a valid workflow task decision.',
            ]);
        }

        if ($task->assigned_user_id !== $actor->id || ! $this->userCanViewTask($actor, $task)) {
            abort(403, 'Only the resolved approver can decide this workflow task.');
        }

        if ($task->status !== ProposalWorkflowTask::STATUS_AVAILABLE) {
            throw ValidationException::withMessages([
                'task' => 'Only available workflow tasks can be decided.',
            ]);
        }

        if (in_array($decision, [
            ProposalWorkflowTask::DECISION_REJECTED,
            ProposalWorkflowTask::DECISION_REVISION_REQUESTED,
        ], true) && blank($remarks)) {
            throw ValidationException::withMessages([
                'remarks' => 'Remarks are required for rejected and revision requested decisions.',
            ]);
        }

        if ($decision === ProposalWorkflowTask::DECISION_NOT_REQUIRED && ! $this->taskAllowsNotRequired($task)) {
            throw ValidationException::withMessages([
                'decision' => 'Only optional workflow steps can be marked not required.',
            ]);
        }
    }

    private function taskAllowsNotRequired(ProposalWorkflowTask $task): bool
    {
        $task->loadMissing('processStep');

        return ! (bool) $task->processStep?->is_required
            || (bool) data_get($task->processStep?->condition_config, 'allow_not_required', false);
    }

    private function statusForDecision(string $decision): string
    {
        return match ($decision) {
            ProposalWorkflowTask::DECISION_APPROVED => ProposalWorkflowTask::STATUS_APPROVED,
            ProposalWorkflowTask::DECISION_REJECTED => ProposalWorkflowTask::STATUS_REJECTED,
            ProposalWorkflowTask::DECISION_REVISION_REQUESTED => ProposalWorkflowTask::STATUS_REVISION_REQUESTED,
            ProposalWorkflowTask::DECISION_NOT_REQUIRED => ProposalWorkflowTask::STATUS_NOT_REQUIRED,
        };
    }

    private function referenceId(ProposalWorkflowTask $task): string
    {
        return 'AT-'.$task->id.'-'.Str::upper(Str::random(8));
    }

    private function unblockSatisfiedDependents(ProposalWorkflowTask $task, \DateTimeInterface $availableAt): void
    {
        $tasks = ProposalWorkflowTask::query()
            ->where('workflow_run_id', $task->workflow_run_id)
            ->lockForUpdate()
            ->get()
            ->keyBy('process_step_id');

        $blockedTasks = $tasks->filter(
            fn (ProposalWorkflowTask $candidate): bool => $candidate->status === ProposalWorkflowTask::STATUS_BLOCKED
        );

        $dependencyPrerequisites = ProposalProcessStepDependency::query()
            ->whereIn('step_id', $blockedTasks->pluck('process_step_id'))
            ->get(['step_id', 'prerequisite_step_id'])
            ->groupBy('step_id')
            ->map(fn ($dependencies) => $dependencies->pluck('prerequisite_step_id'));

        foreach ($blockedTasks as $blockedTask) {
            $prerequisiteStepIds = $dependencyPrerequisites->get($blockedTask->process_step_id, collect());
            $prerequisitesSatisfied = $prerequisiteStepIds->every(function (string $prerequisiteStepId) use ($tasks): bool {
                $prerequisiteTask = $tasks->get($prerequisiteStepId);

                return $prerequisiteTask !== null
                    && in_array($prerequisiteTask->status, self::SATISFIED_PREREQUISITE_STATUSES, true);
            });

            if ($prerequisitesSatisfied) {
                $blockedTask->update([
                    'status' => ProposalWorkflowTask::STATUS_AVAILABLE,
                    'available_at' => $availableAt,
                ]);
            }
        }
    }

    private function markRunApprovedWhenComplete(ProposalWorkflowRun $run, \DateTimeInterface $completedAt): void
    {
        $hasOpenRequiredTask = ProposalWorkflowTask::query()
            ->where('workflow_run_id', $run->id)
            ->whereHas('processStep', fn (Builder $query): Builder => $query->where('is_required', true))
            ->whereNotIn('status', self::SATISFIED_PREREQUISITE_STATUSES)
            ->exists();

        if (! $hasOpenRequiredTask) {
            $run->update([
                'status' => ProposalWorkflowRun::STATUS_APPROVED,
                'completed_at' => $completedAt,
            ]);
        }
    }

    private function markRunAndProposalRejected(ProposalWorkflowRun $run, Event $event, \DateTimeInterface $completedAt): void
    {
        $run->update([
            'status' => ProposalWorkflowRun::STATUS_REJECTED,
            'completed_at' => $completedAt,
        ]);

        $event->update([
            'status' => Event::STATUS_REJECTED,
        ]);
    }

    private function markRunAndProposalRevisionRequested(ProposalWorkflowRun $run, Event $event): void
    {
        $run->update([
            'status' => ProposalWorkflowRun::STATUS_REVISION_REQUESTED,
            'completed_at' => null,
        ]);

        $event->update([
            'status' => Event::STATUS_REVISION_REQUESTED,
        ]);
    }
}
