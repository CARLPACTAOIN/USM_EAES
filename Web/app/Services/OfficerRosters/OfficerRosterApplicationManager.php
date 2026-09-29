<?php

namespace App\Services\OfficerRosters;

use App\Models\Organization;
use App\Models\OrganizationOfficer;
use App\Models\OrganizationOfficerRoster;
use App\Models\SchoolYear;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OfficerRosterApplicationManager
{
    public function __construct(
        private readonly OfficerRosterAuthorization $authorization,
        private readonly OfficerRosterEvidenceHandler $evidence,
        private readonly OfficerRosterOfficerSynchronizer $officers
    ) {}

    /**
     * @param  array{officers?: array<int, array{user_id: string, position_key: string, position_title?: ?string}>, attestation_text?: ?string}  $data
     */
    public function saveDraft(
        Organization $organization,
        SchoolYear $schoolYear,
        array $data,
        User $actor,
        ?UploadedFile $evidenceFile = null
    ): OrganizationOfficerRoster {
        $this->authorization->authorizePreparer($actor, $organization);
        $this->authorization->assertSchoolYearOpen($schoolYear);

        return DB::transaction(function () use ($organization, $schoolYear, $data, $actor, $evidenceFile): OrganizationOfficerRoster {
            $roster = $this->editableRoster($organization, $schoolYear, $actor);

            if (isset($data['attestation_text'])) {
                $roster->attestation_text = $data['attestation_text'];
            }

            $this->evidence->applyRosterEvidence($roster, $evidenceFile);
            $roster->save();

            if (isset($data['officers']) && is_array($data['officers'])) {
                $this->officers->syncOfficers($roster, $organization, $data['officers']);
            }

            return $roster->load('officers.user');
        });
    }

    /**
     * @param  array{officers?: array<int, array{user_id: string, position_key: string, position_title?: ?string}>, attestation_text?: ?string}  $data
     */
    public function submit(
        Organization $organization,
        SchoolYear $schoolYear,
        array $data,
        User $actor,
        ?UploadedFile $evidenceFile = null
    ): OrganizationOfficerRoster {
        $this->authorization->authorizePreparer($actor, $organization);
        $this->authorization->assertSchoolYearOpen($schoolYear);

        return DB::transaction(function () use ($organization, $schoolYear, $data, $actor, $evidenceFile): OrganizationOfficerRoster {
            $roster = $this->editableRoster($organization, $schoolYear, $actor);

            if (isset($data['attestation_text'])) {
                $roster->attestation_text = $data['attestation_text'];
            }

            $this->evidence->applyRosterEvidence($roster, $evidenceFile);

            $officersList = $data['officers'] ?? null;
            if ($officersList !== null && is_array($officersList)) {
                $roster->save();
                $this->officers->syncOfficers($roster, $organization, $officersList);
            } else {
                $roster->loadMissing('officers.user');
                $this->officers->validateExistingOfficers($roster, $organization);
            }

            if (empty($roster->evidence_file_path) && empty($roster->attestation_text)) {
                throw ValidationException::withMessages([
                    'evidence' => 'Supporting evidence file or official attestation statement is required prior to submission.',
                ]);
            }

            $roster->load('officers');
            $hasPresident = $roster->officers->contains(fn ($officer) => $officer->position_key === OrganizationOfficer::POSITION_PRESIDENT && $officer->status === OrganizationOfficer::STATUS_ACTIVE);
            $hasSecretary = $roster->officers->contains(fn ($officer) => $officer->position_key === OrganizationOfficer::POSITION_SECRETARY && $officer->status === OrganizationOfficer::STATUS_ACTIVE);
            $hasAdviser = $roster->officers->contains(fn ($officer) => $officer->position_key === OrganizationOfficer::POSITION_ADVISER && $officer->status === OrganizationOfficer::STATUS_ACTIVE);

            if (! $hasPresident || ! $hasSecretary || ! $hasAdviser) {
                throw ValidationException::withMessages([
                    'roles' => 'A submitted officer roster requires an active President, Secretary, and Adviser.',
                ]);
            }

            $roster->status = OrganizationOfficerRoster::STATUS_SUBMITTED;
            $roster->submitted_by = $actor->id;
            $roster->submitted_at = now();
            $roster->save();

            return $roster->load('officers.user');
        });
    }

    private function editableRoster(
        Organization $organization,
        SchoolYear $schoolYear,
        User $actor
    ): OrganizationOfficerRoster {
        $roster = OrganizationOfficerRoster::firstOrNew([
            'organization_id' => $organization->id,
            'school_year_id' => $schoolYear->id,
        ]);

        if ($roster->exists && ! $roster->isEditable()) {
            throw ValidationException::withMessages([
                'roster' => 'This roster has already been submitted and cannot be modified.',
            ]);
        }

        if (! $roster->exists) {
            $roster->created_by = $actor->id;
            $roster->status = OrganizationOfficerRoster::STATUS_DRAFT;
        }

        return $roster;
    }
}
