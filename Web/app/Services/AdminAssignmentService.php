<?php

namespace App\Services;

use App\Models\AdminApplication;
use App\Models\AdminAssignment;
use App\Models\AuditLog;
use App\Models\College;
use App\Models\Organization;
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
        'ARO Admin',
    ];

    public function approveApplication(AdminApplication $application, User $approver, ?string $remarks = null): AdminAssignment
    {
        return DB::transaction(function () use ($application, $approver, $remarks) {
            $application->refresh();

            if ($application->status !== AdminApplication::STATUS_PENDING) {
                throw ValidationException::withMessages([
                    'application' => 'Only pending applications can be approved.',
                ]);
            }

            $organization = $this->resolveOrganizationForApplication($application);

            $assignment = $this->createAssignment(
                user: $application->applicant,
                roleName: $application->role_name,
                organization: $organization,
                college: $organization?->college ?: $application->college,
                academicYear: $application->academic_year,
                termStart: $application->term_start?->toDateString(),
                termEnd: $application->term_end?->toDateString(),
                positionTitle: $application->position_title,
                approvedBy: $approver,
                isPrimaryAdmin: true
            );

            $application->update([
                'organization_id' => $organization?->id,
                'status' => AdminApplication::STATUS_APPROVED,
                'review_remarks' => $remarks,
                'reviewed_by' => $approver->id,
                'reviewed_at' => now(),
            ]);

            AuditLog::create([
                'target_id' => $application->id,
                'admin_id' => $approver->id,
                'action' => 'approve-admin-application',
                'details' => [
                    'assignment_id' => $assignment->id,
                    'role_name' => $assignment->role_name,
                    'organization_id' => $assignment->organization_id,
                    'college_id' => $assignment->college_id,
                    'academic_year' => $assignment->academic_year,
                ],
            ]);

            return $assignment;
        });
    }

    public function rejectApplication(AdminApplication $application, User $reviewer, string $remarks): void
    {
        DB::transaction(function () use ($application, $reviewer, $remarks) {
            $application->refresh();

            if ($application->status !== AdminApplication::STATUS_PENDING) {
                throw ValidationException::withMessages([
                    'application' => 'Only pending applications can be rejected.',
                ]);
            }

            $application->update([
                'status' => AdminApplication::STATUS_REJECTED,
                'review_remarks' => $remarks,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
            ]);

            AuditLog::create([
                'target_id' => $application->id,
                'admin_id' => $reviewer->id,
                'action' => 'reject-admin-application',
                'details' => [
                    'role_name' => $application->role_name,
                    'organization_id' => $application->organization_id,
                    'college_id' => $application->college_id,
                    'remarks' => $remarks,
                ],
            ]);
        });
    }

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
        bool $isPrimaryAdmin = true
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
            $isPrimaryAdmin
        ) {
            if ($isPrimaryAdmin) {
                $this->assertPrimarySlotAvailable($roleName, $organization, $college, $academicYear);
            }

            $assignment = AdminAssignment::create([
                'user_id' => $user->id,
                'role_name' => $roleName,
                'organization_id' => $organization?->id,
                'college_id' => $college?->id ?: $organization?->college_id,
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
            ->where('status', AdminAssignment::STATUS_ACTIVE)
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

        $primary = $activeAssignments->firstWhere('is_primary_admin', true) ?: $activeAssignments->first();

        $user->forceFill([
            'organization_id' => $primary?->organization_id,
            'college_id' => $primary?->college_id,
        ])->save();
    }

    private function resolveOrganizationForApplication(AdminApplication $application): ?Organization
    {
        if ($application->request_type === 'new_society') {
            $organization = Organization::create([
                'college_id' => $application->college_id,
                'name' => $application->organization_name,
                'acronym' => $application->organization_acronym,
                'type' => 'society',
                'logo_path' => $application->logo_path,
                'status' => 'active',
            ]);

            $organization->programs()->sync($application->programs()->pluck('programs.id')->all());

            return $organization;
        }

        if ($application->organization_id) {
            $organization = $application->organization;

            if ($organization && $application->logo_path && !$organization->logo_path) {
                $organization->update(['logo_path' => $application->logo_path]);
            }

            return $organization;
        }

        return match ($application->request_type) {
            'existing_usg' => Organization::where('type', 'usg')->first(),
            'existing_aro' => Organization::where('type', 'aro')->first(),
            'existing_lsg' => Organization::where('type', 'lsg')->where('college_id', $application->college_id)->first(),
            default => null,
        };
    }

    private function assertAdminRole(string $roleName): void
    {
        if (!in_array($roleName, self::ADMIN_ROLES, true)) {
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
