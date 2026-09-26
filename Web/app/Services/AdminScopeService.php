<?php

namespace App\Services;

use App\Models\AdminAssignment;
use App\Models\College;
use App\Models\SchoolYear;
use App\Models\User;
use App\Support\AdminScope;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;

class AdminScopeService
{
    public const SESSION_KEY = 'active_authority_assignment_id';

    public const HEADER_NAME = 'X-Authority-Context';

    public function __construct(private readonly Request $request) {}

    public function forUser(User $user): ?AdminScope
    {
        if ($user->hasRole('Super Admin (OSA)')) {
            return new AdminScope(
                assignment: null,
                roleName: 'Super Admin (OSA)',
                organizationId: null,
                collegeId: null,
                departmentId: null,
                schoolYearId: null,
                organization: null,
                college: null,
            );
        }

        $assignments = $this->availableAssignmentsFor($user);
        $selectedAssignmentId = $this->selectedAssignmentId();

        if ($assignments->count() > 1 && blank($selectedAssignmentId)) {
            return null;
        }

        $assignment = $assignments->count() === 1
            ? $assignments->first()
            : $assignments->firstWhere('id', $selectedAssignmentId);

        if ($assignment) {
            $organization = $assignment->organization;
            $collegeId = $assignment->college_id ?: $organization?->college_id;
            $college = $assignment->college ?: ($collegeId ? College::find($collegeId) : null);

            return new AdminScope(
                assignment: $assignment,
                roleName: $assignment->role_name,
                organizationId: $assignment->organization_id,
                collegeId: $collegeId,
                departmentId: $assignment->department_id ?: $organization?->department_id,
                schoolYearId: $assignment->school_year_id,
                organization: $organization,
                college: $college,
            );
        }

        return null;
    }

    /**
     * @return Collection<int, AdminAssignment>
     */
    public function availableAssignmentsFor(User $user): Collection
    {
        return $user->adminAssignments()
            ->with(['organization', 'college', 'department', 'schoolYear'])
            ->currentlyEffective()
            ->whereIn('role_name', AdminAssignmentService::ADMIN_ROLES)
            ->where(function ($query): void {
                $query->whereNull('school_year_id')
                    ->orWhereHas('schoolYear', fn ($schoolYearQuery) => $schoolYearQuery
                        ->where('status', SchoolYear::STATUS_ACTIVE));
            })
            ->orderByDesc('is_primary_admin')
            ->orderByDesc('approved_at')
            ->get();
    }

    public function requiresSelection(User $user): bool
    {
        if ($user->hasRole('Super Admin (OSA)')) {
            return false;
        }

        $assignments = $this->availableAssignmentsFor($user);

        return $assignments->count() > 1
            && ! $assignments->contains('id', $this->selectedAssignmentId());
    }

    public function requiresAuthorityContext(User $user): bool
    {
        return $this->lacksAuthorityAssignment($user) || $this->requiresSelection($user);
    }

    public function lacksAuthorityAssignment(User $user): bool
    {
        if ($user->hasRole('Super Admin (OSA)')) {
            return false;
        }

        if (! $user->hasAnyRole(AdminAssignmentService::ADMIN_ROLES)) {
            return false;
        }

        $assignments = $this->availableAssignmentsFor($user);

        return $assignments->isEmpty();
    }

    private function selectedAssignmentId(): ?string
    {
        $headerContext = $this->request->header(self::HEADER_NAME);

        if (filled($headerContext)) {
            return (string) $headerContext;
        }

        if ($this->request->hasSession()) {
            $sessionContext = $this->request->session()->get(self::SESSION_KEY);

            return filled($sessionContext) ? (string) $sessionContext : null;
        }

        return null;
    }
}
