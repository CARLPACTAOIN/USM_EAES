<?php

namespace App\Services;

use App\Models\AdminAssignment;
use App\Models\OrganizationOfficer;
use App\Models\OrganizationOfficerRoster;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class OfficerAuthorityProjectionService
{
    public function __construct(private readonly AdminAssignmentService $assignments) {}

    public function synchronize(OrganizationOfficerRoster $roster, User $approvedBy): void
    {
        DB::transaction(function () use ($roster, $approvedBy): void {
            $roster->load(['organization', 'schoolYear', 'officers.user']);

            $authorityOfficers = $roster->officers
                ->where('status', OrganizationOfficer::STATUS_ACTIVE)
                ->whereIn('position_key', [
                    OrganizationOfficer::POSITION_PRESIDENT,
                    OrganizationOfficer::POSITION_SECRETARY,
                ]);
            $sourceIds = $authorityOfficers->pluck('id');

            $staleAssignments = AdminAssignment::query()
                ->where('source_type', AdminAssignment::SOURCE_VERIFIED_OFFICER)
                ->where('organization_id', $roster->organization_id)
                ->where('school_year_id', $roster->school_year_id)
                ->where('status', AdminAssignment::STATUS_ACTIVE)
                ->when($sourceIds->isNotEmpty(), fn ($query) => $query->whereNotIn('source_id', $sourceIds))
                ->get();

            foreach ($staleAssignments as $staleAssignment) {
                $this->revoke($staleAssignment, $approvedBy, 'Officer record is no longer active.');
            }

            $legacyAssignments = AdminAssignment::query()
                ->where('organization_id', $roster->organization_id)
                ->where('academic_year', $roster->schoolYear->label)
                ->where('status', AdminAssignment::STATUS_ACTIVE)
                ->whereNull('source_type')
                ->where(function ($query): void {
                    $query->where('is_primary_admin', true)
                        ->orWhereIn('position_title', ['President', 'Secretary']);
                })
                ->get();

            foreach ($legacyAssignments as $legacyAssignment) {
                $legacyAssignment->update([
                    'status' => AdminAssignment::STATUS_EXPIRED,
                    'revoked_by' => $approvedBy->id,
                    'revoked_at' => now(),
                    'revocation_reason' => 'Superseded by verified Officer Roster authority.',
                ]);
            }

            foreach ($authorityOfficers as $officer) {
                $assignment = AdminAssignment::query()->updateOrCreate(
                    [
                        'source_type' => AdminAssignment::SOURCE_VERIFIED_OFFICER,
                        'source_id' => $officer->id,
                    ],
                    [
                        'user_id' => $officer->user_id,
                        'role_name' => $this->roleNameFor($roster),
                        'organization_id' => $roster->organization_id,
                        'college_id' => $roster->organization->college_id,
                        'department_id' => $roster->organization->department_id,
                        'school_year_id' => $roster->school_year_id,
                        'academic_year' => $roster->schoolYear->label,
                        'term_start' => $officer->effective_start ?? $roster->schoolYear->starts_on,
                        'term_end' => $officer->effective_end ?? $roster->schoolYear->ends_on,
                        'position_title' => $officer->position_title,
                        'status' => AdminAssignment::STATUS_ACTIVE,
                        'is_primary_admin' => $officer->position_key === OrganizationOfficer::POSITION_PRESIDENT,
                        'approved_by' => $approvedBy->id,
                        'approved_at' => now(),
                        'revoked_by' => null,
                        'revoked_at' => null,
                        'revocation_reason' => null,
                    ]
                );

                $this->assignments->syncUserProjection($assignment->user);
            }

            foreach ($staleAssignments->merge($legacyAssignments)->pluck('user')->filter()->unique('id') as $user) {
                $this->assignments->syncUserProjection($user->fresh());
            }
        });
    }

    private function revoke(AdminAssignment $assignment, User $revokedBy, string $reason): void
    {
        $assignment->update([
            'status' => AdminAssignment::STATUS_REVOKED,
            'revoked_by' => $revokedBy->id,
            'revoked_at' => now(),
            'revocation_reason' => $reason,
        ]);
    }

    private function roleNameFor(OrganizationOfficerRoster $roster): string
    {
        return match ($roster->organization->type) {
            'usg' => 'USG Admin',
            'lsg' => 'LSG Admin',
            'academic_unit' => 'Academic Unit Admin',
            'administrative_office' => 'Office Event Admin',
            'hr_unit' => 'HR Admin',
            default => 'Society Admin',
        };
    }
}
