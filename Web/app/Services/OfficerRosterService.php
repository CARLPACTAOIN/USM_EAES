<?php

namespace App\Services;

use App\Models\OfficerChangeRequest;
use App\Models\Organization;
use App\Models\OrganizationOfficerRoster;
use App\Models\SchoolYear;
use App\Models\User;
use App\Services\OfficerRosters\OfficerChangeRequestManager;
use App\Services\OfficerRosters\OfficerRosterApplicationManager;
use App\Services\OfficerRosters\OfficerRosterAuthorization;
use App\Services\OfficerRosters\OfficerRosterVerificationManager;
use Illuminate\Http\UploadedFile;

class OfficerRosterService
{
    public function __construct(
        private readonly OfficerRosterAuthorization $authorization,
        private readonly OfficerRosterApplicationManager $applications,
        private readonly OfficerRosterVerificationManager $verification,
        private readonly OfficerChangeRequestManager $changeRequests
    ) {}

    public function canPrepareRoster(User $user, Organization $organization): bool
    {
        return $this->authorization->canPrepareRoster($user, $organization);
    }

    public function isOutgoingPresidentOrSecretary(User $user, Organization $organization): bool
    {
        return $this->authorization->isOutgoingPresidentOrSecretary($user, $organization);
    }

    public function outgoingOfficersAvailable(Organization $organization): bool
    {
        return $this->authorization->outgoingOfficersAvailable($organization);
    }

    public function isEligibleAdviser(User $user, ?Organization $organization = null): bool
    {
        return $this->authorization->isEligibleAdviser($user, $organization);
    }

    public function assertSchoolYearOpen(SchoolYear $schoolYear): void
    {
        $this->authorization->assertSchoolYearOpen($schoolYear);
    }

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
        return $this->applications->saveDraft($organization, $schoolYear, $data, $actor, $evidenceFile);
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
        return $this->applications->submit($organization, $schoolYear, $data, $actor, $evidenceFile);
    }

    public function canVerifyRoster(User $user, OrganizationOfficerRoster $roster): bool
    {
        return $this->authorization->canVerifyRoster($user, $roster);
    }

    public function authorizeAdviser(User $user, OrganizationOfficerRoster $roster): void
    {
        $this->authorization->authorizeAdviser($user, $roster);
    }

    public function verifyRoster(OrganizationOfficerRoster $roster, User $adviser, ?string $remarks = null): OrganizationOfficerRoster
    {
        return $this->verification->verifyRoster($roster, $adviser, $remarks);
    }

    public function requestChanges(OrganizationOfficerRoster $roster, User $adviser, string $remarks): OrganizationOfficerRoster
    {
        return $this->verification->requestChanges($roster, $adviser, $remarks);
    }

    public function projectOfficerAuthority(OrganizationOfficerRoster $roster, User $adviser): void
    {
        $this->verification->projectOfficerAuthority($roster, $adviser);
    }

    public function canRequestOfficerChange(User $user, Organization $organization): bool
    {
        return $this->authorization->canRequestOfficerChange($user, $organization);
    }

    public function authorizeChangeRequester(User $user, Organization $organization): void
    {
        $this->authorization->authorizeChangeRequester($user, $organization);
    }

    /**
     * @param  array{old_officer_id: string, request_type: string, new_user_id?: ?string, position_title?: ?string, reason: string}  $data
     */
    public function createChangeRequest(
        Organization $organization,
        array $data,
        User $actor,
        ?UploadedFile $evidenceFile = null
    ): OfficerChangeRequest {
        return $this->changeRequests->createChangeRequest($organization, $data, $actor, $evidenceFile);
    }

    public function approveChangeRequest(
        OfficerChangeRequest $changeRequest,
        User $adviser,
        ?string $remarks = null
    ): OfficerChangeRequest {
        return $this->changeRequests->approveChangeRequest($changeRequest, $adviser, $remarks);
    }

    public function rejectChangeRequest(
        OfficerChangeRequest $changeRequest,
        User $adviser,
        string $remarks
    ): OfficerChangeRequest {
        return $this->changeRequests->rejectChangeRequest($changeRequest, $adviser, $remarks);
    }
}
