<?php

namespace App\Services\OfficerRosters;

use App\Models\AuditLog;
use App\Models\OrganizationOfficer;
use App\Models\OrganizationOfficerRoster;
use App\Models\User;
use App\Services\OfficerAuthorityProjectionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OfficerRosterVerificationManager
{
    public function __construct(
        private readonly OfficerRosterAuthorization $authorization,
        private readonly OfficerAuthorityProjectionService $authorityProjections
    ) {}

    public function verifyRoster(OrganizationOfficerRoster $roster, User $adviser, ?string $remarks = null): OrganizationOfficerRoster
    {
        $this->authorization->authorizeAdviser($adviser, $roster);

        if ($roster->status !== OrganizationOfficerRoster::STATUS_SUBMITTED) {
            throw ValidationException::withMessages([
                'roster' => 'Only submitted officer rosters can be verified.',
            ]);
        }

        $this->authorization->assertSchoolYearOpen($roster->schoolYear);

        return DB::transaction(function () use ($roster, $adviser, $remarks): OrganizationOfficerRoster {
            $previousVerifiedRosters = OrganizationOfficerRoster::query()
                ->where('organization_id', $roster->organization_id)
                ->where('school_year_id', $roster->school_year_id)
                ->where('id', '!=', $roster->id)
                ->where('status', OrganizationOfficerRoster::STATUS_VERIFIED)
                ->get();

            foreach ($previousVerifiedRosters as $previousRoster) {
                $previousRoster->update(['status' => OrganizationOfficerRoster::STATUS_SUPERSEDED]);
                $previousRoster->officers()->where('status', OrganizationOfficer::STATUS_ACTIVE)->update([
                    'status' => OrganizationOfficer::STATUS_EXPIRED,
                    'effective_end' => now()->toDateString(),
                ]);
            }

            $roster->status = OrganizationOfficerRoster::STATUS_VERIFIED;
            $roster->verified_by = $adviser->id;
            $roster->verified_at = now();
            $roster->verification_remarks = $remarks;
            $roster->rejection_remarks = null;
            $roster->save();

            foreach ($roster->officers as $officer) {
                if ($officer->status === OrganizationOfficer::STATUS_ACTIVE && ! $officer->effective_start) {
                    $officer->effective_start = now()->toDateString();
                    $officer->save();
                }
            }

            $this->projectOfficerAuthority($roster, $adviser);

            AuditLog::create([
                'target_id' => $roster->id,
                'admin_id' => $adviser->id,
                'action' => 'verify-officer-roster',
                'details' => [
                    'organization_id' => $roster->organization_id,
                    'school_year_id' => $roster->school_year_id,
                    'remarks' => $remarks,
                ],
            ]);

            return $roster->load(['officers.user', 'verifiedBy', 'submittedBy']);
        });
    }

    public function requestChanges(OrganizationOfficerRoster $roster, User $adviser, string $remarks): OrganizationOfficerRoster
    {
        $this->authorization->authorizeAdviser($adviser, $roster);

        if ($roster->status !== OrganizationOfficerRoster::STATUS_SUBMITTED) {
            throw ValidationException::withMessages([
                'roster' => 'Only submitted officer rosters can have changes requested.',
            ]);
        }

        if (trim($remarks) === '') {
            throw ValidationException::withMessages([
                'remarks' => 'Remarks are required when requesting changes on an officer roster.',
            ]);
        }

        return DB::transaction(function () use ($roster, $adviser, $remarks): OrganizationOfficerRoster {
            $roster->status = OrganizationOfficerRoster::STATUS_CHANGES_REQUESTED;
            $roster->rejection_remarks = $remarks;
            $roster->save();

            AuditLog::create([
                'target_id' => $roster->id,
                'admin_id' => $adviser->id,
                'action' => 'request-changes-officer-roster',
                'details' => [
                    'organization_id' => $roster->organization_id,
                    'school_year_id' => $roster->school_year_id,
                    'remarks' => $remarks,
                ],
            ]);

            return $roster->load(['officers.user', 'verifiedBy', 'submittedBy']);
        });
    }

    public function projectOfficerAuthority(OrganizationOfficerRoster $roster, User $adviser): void
    {
        $this->authorityProjections->synchronize($roster, $adviser);
    }
}
