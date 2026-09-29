<?php

namespace App\Services\OfficerRosters;

use App\Models\AuditLog;
use App\Models\OfficerChangeRequest;
use App\Models\Organization;
use App\Models\OrganizationOfficer;
use App\Models\OrganizationOfficerRoster;
use App\Models\User;
use App\Services\OfficerAuthorityProjectionService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OfficerChangeRequestManager
{
    public function __construct(
        private readonly OfficerRosterAuthorization $authorization,
        private readonly OfficerRosterEvidenceHandler $evidence,
        private readonly OfficerRosterOfficerSynchronizer $officers,
        private readonly OfficerAuthorityProjectionService $authorityProjections
    ) {}

    /**
     * @param  array{old_officer_id: string, request_type: string, new_user_id?: ?string, position_title?: ?string, reason: string}  $data
     */
    public function createChangeRequest(
        Organization $organization,
        array $data,
        User $actor,
        ?UploadedFile $evidenceFile = null
    ): OfficerChangeRequest {
        $this->authorization->authorizeChangeRequester($actor, $organization);

        $roster = OrganizationOfficerRoster::query()
            ->where('organization_id', $organization->id)
            ->where('status', OrganizationOfficerRoster::STATUS_VERIFIED)
            ->latest('verified_at')
            ->first();

        if (! $roster) {
            throw ValidationException::withMessages([
                'roster' => 'An active verified officer roster is required before submitting change requests.',
            ]);
        }

        $oldOfficer = OrganizationOfficer::query()
            ->where('officer_roster_id', $roster->id)
            ->where('id', $data['old_officer_id'] ?? null)
            ->first();

        if (! $oldOfficer || $oldOfficer->status !== OrganizationOfficer::STATUS_ACTIVE) {
            throw ValidationException::withMessages([
                'old_officer_id' => 'The selected officer must be an active officer on the verified roster.',
            ]);
        }

        $requestType = Str::of((string) ($data['request_type'] ?? ''))->trim()->lower()->toString();
        if (! in_array($requestType, [
            OfficerChangeRequest::TYPE_RESIGNATION,
            OfficerChangeRequest::TYPE_REPLACEMENT,
            OfficerChangeRequest::TYPE_REVOCATION,
            OfficerChangeRequest::TYPE_CORRECTION,
        ], true)) {
            throw ValidationException::withMessages([
                'request_type' => 'Invalid change request type.',
            ]);
        }

        $newUserId = $data['new_user_id'] ?? null;
        if ($requestType === OfficerChangeRequest::TYPE_REPLACEMENT && empty($newUserId)) {
            throw ValidationException::withMessages([
                'new_user_id' => 'A replacement officer is required for replacement requests.',
            ]);
        }

        if (! empty($newUserId)) {
            $this->validateReplacementUser($organization, $roster, $oldOfficer, $newUserId);
        }

        $storedEvidence = $this->evidence->storeChangeRequestEvidence($evidenceFile);

        return DB::transaction(function () use (
            $organization,
            $roster,
            $oldOfficer,
            $newUserId,
            $requestType,
            $data,
            $storedEvidence,
            $actor
        ): OfficerChangeRequest {
            $changeRequest = OfficerChangeRequest::create([
                'organization_id' => $organization->id,
                'officer_roster_id' => $roster->id,
                'old_officer_id' => $oldOfficer->id,
                'new_user_id' => $newUserId,
                'request_type' => $requestType,
                'position_key' => $oldOfficer->position_key,
                'position_title' => $data['position_title'] ?? $oldOfficer->position_title,
                'status' => OfficerChangeRequest::STATUS_PENDING,
                'reason' => $data['reason'],
                'evidence_file_path' => $storedEvidence['path'],
                'evidence_file_name' => $storedEvidence['name'],
                'evidence_metadata' => $storedEvidence['metadata'],
                'requested_by' => $actor->id,
                'requested_at' => now(),
            ]);

            AuditLog::create([
                'target_id' => $changeRequest->id,
                'admin_id' => $actor->id,
                'action' => 'create-officer-change-request',
                'details' => [
                    'organization_id' => $organization->id,
                    'officer_roster_id' => $roster->id,
                    'old_officer_id' => $oldOfficer->id,
                    'new_user_id' => $newUserId,
                    'request_type' => $requestType,
                ],
            ]);

            return $changeRequest->load(['oldOfficer.user', 'newUser', 'requestedBy', 'roster']);
        });
    }

    public function approveChangeRequest(
        OfficerChangeRequest $changeRequest,
        User $adviser,
        ?string $remarks = null
    ): OfficerChangeRequest {
        $changeRequest->loadMissing('roster.organization', 'oldOfficer');
        $this->authorization->authorizeAdviser($adviser, $changeRequest->roster);

        if ($changeRequest->status !== OfficerChangeRequest::STATUS_PENDING) {
            throw ValidationException::withMessages([
                'change_request' => 'Only pending change requests can be approved.',
            ]);
        }

        return DB::transaction(function () use ($changeRequest, $adviser, $remarks): OfficerChangeRequest {
            $oldOfficer = $changeRequest->oldOfficer;

            $priorStatus = match ($changeRequest->request_type) {
                OfficerChangeRequest::TYPE_REVOCATION => OrganizationOfficer::STATUS_REVOKED,
                OfficerChangeRequest::TYPE_RESIGNATION => $changeRequest->new_user_id ? OrganizationOfficer::STATUS_REPLACED : OrganizationOfficer::STATUS_EXPIRED,
                default => OrganizationOfficer::STATUS_REPLACED,
            };

            $oldOfficer->status = $priorStatus;
            $oldOfficer->effective_end = now()->toDateString();

            if ($changeRequest->new_user_id) {
                $newOfficer = OrganizationOfficer::create([
                    'officer_roster_id' => $changeRequest->officer_roster_id,
                    'user_id' => $changeRequest->new_user_id,
                    'position_key' => $changeRequest->position_key,
                    'position_title' => $changeRequest->position_title,
                    'status' => OrganizationOfficer::STATUS_ACTIVE,
                    'effective_start' => now()->toDateString(),
                    'metadata' => [
                        'change_request_id' => $changeRequest->id,
                        'replaced_officer_id' => $oldOfficer->id,
                    ],
                ]);

                $oldOfficer->replacement_officer_id = $newOfficer->id;
                $changeRequest->created_officer_id = $newOfficer->id;
            }

            $oldOfficer->save();

            if (in_array($changeRequest->position_key, [
                OrganizationOfficer::POSITION_PRESIDENT,
                OrganizationOfficer::POSITION_SECRETARY,
            ], true)) {
                $this->authorityProjections->synchronize($changeRequest->roster, $adviser);
            }

            $changeRequest->status = OfficerChangeRequest::STATUS_APPROVED;
            $changeRequest->decided_by = $adviser->id;
            $changeRequest->decided_at = now();
            $changeRequest->decision_remarks = $remarks;
            $changeRequest->save();

            AuditLog::create([
                'target_id' => $changeRequest->id,
                'admin_id' => $adviser->id,
                'action' => 'approve-officer-change-request',
                'details' => [
                    'old_officer_id' => $oldOfficer->id,
                    'prior_status' => $priorStatus,
                    'created_officer_id' => $changeRequest->created_officer_id,
                    'remarks' => $remarks,
                ],
            ]);

            return $changeRequest->load(['oldOfficer.user', 'newUser', 'createdOfficer.user', 'decidedBy', 'requestedBy', 'roster']);
        });
    }

    public function rejectChangeRequest(
        OfficerChangeRequest $changeRequest,
        User $adviser,
        string $remarks
    ): OfficerChangeRequest {
        $changeRequest->loadMissing('roster.organization');
        $this->authorization->authorizeAdviser($adviser, $changeRequest->roster);

        if ($changeRequest->status !== OfficerChangeRequest::STATUS_PENDING) {
            throw ValidationException::withMessages([
                'change_request' => 'Only pending change requests can be rejected.',
            ]);
        }

        if (trim($remarks) === '') {
            throw ValidationException::withMessages([
                'remarks' => 'Remarks are required when rejecting an officer change request.',
            ]);
        }

        return DB::transaction(function () use ($changeRequest, $adviser, $remarks): OfficerChangeRequest {
            $changeRequest->status = OfficerChangeRequest::STATUS_REJECTED;
            $changeRequest->decided_by = $adviser->id;
            $changeRequest->decided_at = now();
            $changeRequest->decision_remarks = $remarks;
            $changeRequest->save();

            AuditLog::create([
                'target_id' => $changeRequest->id,
                'admin_id' => $adviser->id,
                'action' => 'reject-officer-change-request',
                'details' => [
                    'old_officer_id' => $changeRequest->old_officer_id,
                    'remarks' => $remarks,
                ],
            ]);

            return $changeRequest->load(['oldOfficer.user', 'newUser', 'decidedBy', 'requestedBy']);
        });
    }

    private function validateReplacementUser(
        Organization $organization,
        OrganizationOfficerRoster $roster,
        OrganizationOfficer $oldOfficer,
        string $newUserId
    ): void {
        if ($newUserId === $oldOfficer->user_id) {
            throw ValidationException::withMessages([
                'new_user_id' => 'The replacement officer cannot be the same user.',
            ]);
        }

        $alreadyActive = OrganizationOfficer::query()
            ->where('officer_roster_id', $roster->id)
            ->where('user_id', $newUserId)
            ->where('status', OrganizationOfficer::STATUS_ACTIVE)
            ->exists();

        if ($alreadyActive) {
            throw ValidationException::withMessages([
                'new_user_id' => 'The selected user is already an active officer on this roster.',
            ]);
        }

        $newUser = User::find($newUserId);
        if (! $newUser) {
            throw ValidationException::withMessages([
                'new_user_id' => 'Selected replacement user was not found.',
            ]);
        }

        $this->officers->validateReplacementOfficer($newUser, $organization, $oldOfficer);
    }
}
