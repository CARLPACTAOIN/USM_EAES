<?php

namespace App\Services;

use App\Models\AdminAssignment;
use App\Models\ProposalWorkflowTask;
use App\Models\User;
use App\Models\WorkflowTaskAssignmentHistory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProposalWorkflowTaskService
{
    private const ACTIVE_STATUSES = [
        ProposalWorkflowTask::STATUS_BLOCKED,
        ProposalWorkflowTask::STATUS_AVAILABLE,
        ProposalWorkflowTask::STATUS_IN_PROGRESS,
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

        abort_unless($this->userCanViewTask($user, $task) || $this->userCanAdministerTask($user, $task), 403);

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

    public function userCanViewTask(User $user, ProposalWorkflowTask $task): bool
    {
        if ($task->assigned_user_id !== $user->id) {
            return false;
        }

        if (! in_array($task->status, self::ACTIVE_STATUSES, true)) {
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
            'assignmentHistory.formerAssignee',
            'assignmentHistory.newAssignee',
            'assignmentHistory.actor',
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
}
