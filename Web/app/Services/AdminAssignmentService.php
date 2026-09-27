<?php

namespace App\Services;

use App\Models\AdminAssignment;
use App\Models\AuditLog;
use App\Models\College;
use App\Models\Department;
use App\Models\Organization;
use App\Models\SchoolYear;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdminAssignmentService
{
    public const ADMIN_ROLES = [
        'Super Admin (OSA)',
        'USG Admin',
        'LSG Admin',
        'Society Admin',
        'Academic Unit Admin',
        'Office Event Admin',
        'HR Admin',
    ];

    public const OSA_ASSIGNABLE_ROLES = [
        'USG Admin',
        'LSG Admin',
        'Society Admin',
        'Academic Unit Admin',
        'Office Event Admin',
    ];

    public function createAssignment(
        User $user,
        string $roleName,
        ?Organization $organization,
        ?College $college,
        string $academicYear,
        ?string $termStart,
        ?string $termEnd,
        ?string $positionTitle,
        User $approvedBy,
        bool $isPrimaryAdmin = true,
        ?Department $department = null
    ): AdminAssignment {
        $this->assertAdminRole($roleName);

        return DB::transaction(function () use (
            $user,
            $roleName,
            $organization,
            $college,
            $academicYear,
            $termStart,
            $termEnd,
            $positionTitle,
            $approvedBy,
            $isPrimaryAdmin,
            $department
        ) {
            if ($isPrimaryAdmin) {
                $this->assertPrimarySlotAvailable($roleName, $organization, $college, $academicYear);
            }

            $schoolYear = SchoolYear::firstWhere('label', $academicYear)
                ?? SchoolYear::active();

            $assignment = AdminAssignment::create([
                'user_id' => $user->id,
                'role_name' => $roleName,
                'organization_id' => $organization?->id,
                'college_id' => $college?->id ?: $organization?->college_id,
                'department_id' => $department?->id ?: ($organization?->department_id ?: $user->department_id),
                'school_year_id' => $schoolYear?->id,
                'academic_year' => $academicYear,
                'term_start' => $termStart,
                'term_end' => $termEnd,
                'position_title' => $positionTitle,
                'status' => AdminAssignment::STATUS_ACTIVE,
                'is_primary_admin' => $isPrimaryAdmin,
                'approved_by' => $approvedBy->id,
                'approved_at' => now(),
            ]);

            $this->syncUserProjection($user->fresh());

            AuditLog::create([
                'target_id' => $assignment->id,
                'admin_id' => $approvedBy->id,
                'action' => 'create-admin-assignment',
                'details' => [
                    'user_id' => $user->id,
                    'role_name' => $roleName,
                    'organization_id' => $assignment->organization_id,
                    'college_id' => $assignment->college_id,
                    'academic_year' => $academicYear,
                    'is_primary_admin' => $isPrimaryAdmin,
                ],
            ]);

            return $assignment;
        });
    }

    public function revokeAssignment(AdminAssignment $assignment, User $revokedBy, ?string $reason = null): void
    {
        DB::transaction(function () use ($assignment, $revokedBy, $reason) {
            $assignment->update([
                'status' => AdminAssignment::STATUS_REVOKED,
                'revoked_by' => $revokedBy->id,
                'revoked_at' => now(),
                'revocation_reason' => $reason,
            ]);

            $this->syncUserProjection($assignment->user->fresh());

            AuditLog::create([
                'target_id' => $assignment->id,
                'admin_id' => $revokedBy->id,
                'action' => 'revoke-admin-assignment',
                'details' => [
                    'user_id' => $assignment->user_id,
                    'role_name' => $assignment->role_name,
                    'reason' => $reason,
                ],
            ]);
        });
    }

    public function expireAssignment(AdminAssignment $assignment, User $expiredBy): void
    {
        DB::transaction(function () use ($assignment, $expiredBy) {
            $assignment->update(['status' => AdminAssignment::STATUS_EXPIRED]);

            $this->syncUserProjection($assignment->user->fresh());

            AuditLog::create([
                'target_id' => $assignment->id,
                'admin_id' => $expiredBy->id,
                'action' => 'expire-admin-assignment',
                'details' => [
                    'user_id' => $assignment->user_id,
                    'role_name' => $assignment->role_name,
                ],
            ]);
        });
    }

    public function syncUserProjection(User $user): void
    {
        $activeAssignments = $user->adminAssignments()
            ->currentlyEffective()
            ->orderByDesc('is_primary_admin')
            ->orderByDesc('approved_at')
            ->get();

        foreach (self::ADMIN_ROLES as $roleName) {
            if ($activeAssignments->contains('role_name', $roleName)) {
                $user->assignRole($roleName);
            } elseif ($user->hasRole($roleName)) {
                $user->removeRole($roleName);
            }
        }

        $user->unsetRelation('roles');
    }

    private function assertAdminRole(string $roleName): void
    {
        if (! in_array($roleName, self::ADMIN_ROLES, true)) {
            throw ValidationException::withMessages(['role_name' => 'Invalid administrative role.']);
        }
    }

    private function assertPrimarySlotAvailable(string $roleName, ?Organization $organization, ?College $college, string $academicYear): void
    {
        $exists = AdminAssignment::query()
            ->where('role_name', $roleName)
            ->where('academic_year', $academicYear)
            ->where('status', AdminAssignment::STATUS_ACTIVE)
            ->where('is_primary_admin', true)
            ->where('organization_id', $organization?->id)
            ->where('college_id', $college?->id ?: $organization?->college_id)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'is_primary_admin' => 'An active primary admin already exists for this role and scope in the selected academic year.',
            ]);
        }
    }
}
