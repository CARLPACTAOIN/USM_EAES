<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\ApproveOfficerChangeRequestRequest;
use App\Http\Requests\CreateOfficerChangeRequestRequest;
use App\Http\Requests\RejectOfficerChangeRequestRequest;
use App\Http\Requests\RequestChangesOfficerRosterRequest;
use App\Http\Requests\SaveOfficerRosterDraftRequest;
use App\Http\Requests\SubmitOfficerRosterRequest;
use App\Http\Requests\VerifyOfficerRosterRequest;
use App\Models\OfficerChangeRequest;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\OrganizationOfficer;
use App\Models\OrganizationOfficerRoster;
use App\Models\SchoolYear;
use App\Models\User;
use App\Services\OfficerRosterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OfficerRosterController extends Controller
{
    public function show(
        Request $request,
        Organization $organization,
        OfficerRosterService $service,
        ?SchoolYear $schoolYear = null
    ): JsonResponse {
        $schoolYear ??= SchoolYear::where('status', SchoolYear::STATUS_ACTIVE)->first();

        if (! $schoolYear) {
            abort(404, 'No active School Year found.');
        }

        $roster = OrganizationOfficerRoster::query()
            ->with(['officers.user', 'submittedBy', 'verifiedBy', 'createdBy'])
            ->where('organization_id', $organization->id)
            ->where('school_year_id', $schoolYear->id)
            ->first();

        $this->authorizeRosterGovernanceAccess($request, $organization, $service, $roster);

        return response()->json([
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
                'acronym' => $organization->acronym,
                'type' => $organization->type,
            ],
            'school_year' => [
                'id' => $schoolYear->id,
                'label' => $schoolYear->label,
                'status' => $schoolYear->status,
                'is_transition_open' => $schoolYear->isTransitionOpen(),
            ],
            'can_prepare' => $service->canPrepareRoster($request->user(), $organization),
            'can_verify' => $roster ? $service->canVerifyRoster($request->user(), $roster) : false,
            'can_request_change' => $service->canRequestOfficerChange($request->user(), $organization),
            'roster' => $roster ? $this->serializeRoster($roster) : null,
        ]);
    }

    public function saveDraft(
        SaveOfficerRosterDraftRequest $request,
        Organization $organization,
        SchoolYear $schoolYear,
        OfficerRosterService $service
    ): JsonResponse {
        $roster = $service->saveDraft(
            $organization,
            $schoolYear,
            $request->validated(),
            $request->user(),
            $request->file('evidence_file')
        );

        return response()->json([
            'message' => 'Officer roster draft saved successfully.',
            'roster' => $this->serializeRoster($roster),
        ]);
    }

    public function submit(
        SubmitOfficerRosterRequest $request,
        Organization $organization,
        SchoolYear $schoolYear,
        OfficerRosterService $service
    ): JsonResponse {
        $roster = $service->submit(
            $organization,
            $schoolYear,
            $request->validated(),
            $request->user(),
            $request->file('evidence_file')
        );

        return response()->json([
            'message' => 'Officer roster submitted successfully.',
            'roster' => $this->serializeRoster($roster),
        ]);
    }

    public function verify(
        VerifyOfficerRosterRequest $request,
        Organization $organization,
        OrganizationOfficerRoster $roster,
        OfficerRosterService $service
    ): JsonResponse {
        $this->abortIfRosterDoesNotBelongToOrganization($roster, $organization);

        $verifiedRoster = $service->verifyRoster(
            $roster,
            $request->user(),
            $request->input('remarks')
        );

        return response()->json([
            'message' => 'Officer roster verified successfully.',
            'roster' => $this->serializeRoster($verifiedRoster),
        ]);
    }

    public function requestChanges(
        RequestChangesOfficerRosterRequest $request,
        Organization $organization,
        OrganizationOfficerRoster $roster,
        OfficerRosterService $service
    ): JsonResponse {
        $this->abortIfRosterDoesNotBelongToOrganization($roster, $organization);

        $updatedRoster = $service->requestChanges(
            $roster,
            $request->user(),
            $request->input('remarks')
        );

        return response()->json([
            'message' => 'Changes requested on officer roster successfully.',
            'roster' => $this->serializeRoster($updatedRoster),
        ]);
    }

    public function listChangeRequests(
        Request $request,
        Organization $organization,
        OfficerRosterService $service
    ): JsonResponse {
        $this->authorizeChangeRequestAccess($request, $organization, $service);

        $changeRequests = OfficerChangeRequest::query()
            ->where('organization_id', $organization->id)
            ->with(['oldOfficer.user', 'newUser', 'createdOfficer.user', 'requestedBy', 'decidedBy'])
            ->latest('requested_at')
            ->get();

        return response()->json([
            'requests' => $changeRequests->map(fn (OfficerChangeRequest $cr): array => $this->serializeChangeRequest($cr)),
        ]);
    }

    public function createChangeRequest(
        CreateOfficerChangeRequestRequest $request,
        Organization $organization,
        OfficerRosterService $service
    ): JsonResponse {
        $changeRequest = $service->createChangeRequest(
            $organization,
            $request->validated(),
            $request->user(),
            $request->file('evidence_file')
        );

        return response()->json([
            'message' => 'Officer change request submitted successfully.',
            'request' => $this->serializeChangeRequest($changeRequest),
        ]);
    }

    public function approveChangeRequest(
        ApproveOfficerChangeRequestRequest $request,
        Organization $organization,
        OfficerChangeRequest $changeRequest,
        OfficerRosterService $service
    ): JsonResponse {
        $this->abortIfChangeRequestDoesNotBelongToOrganization($changeRequest, $organization);

        $approvedRequest = $service->approveChangeRequest(
            $changeRequest,
            $request->user(),
            $request->input('remarks')
        );

        return response()->json([
            'message' => 'Officer change request approved successfully.',
            'request' => $this->serializeChangeRequest($approvedRequest),
        ]);
    }

    public function rejectChangeRequest(
        RejectOfficerChangeRequestRequest $request,
        Organization $organization,
        OfficerChangeRequest $changeRequest,
        OfficerRosterService $service
    ): JsonResponse {
        $this->abortIfChangeRequestDoesNotBelongToOrganization($changeRequest, $organization);

        $rejectedRequest = $service->rejectChangeRequest(
            $changeRequest,
            $request->user(),
            $request->input('remarks')
        );

        return response()->json([
            'message' => 'Officer change request rejected successfully.',
            'request' => $this->serializeChangeRequest($rejectedRequest),
        ]);
    }

    public function eligibleMembers(
        Request $request,
        Organization $organization,
        OfficerRosterService $service
    ): JsonResponse {
        $this->authorizeRosterGovernanceAccess($request, $organization, $service);

        $members = User::query()
            ->whereHas('organizationMemberships', function ($query) use ($organization): void {
                $query->where('organization_id', $organization->id)
                    ->where('status', OrganizationMembership::STATUS_ACTIVE);
            })
            ->where(function ($query): void {
                $query->where('user_type', 'student')
                    ->orWhereHas('roles', fn ($q) => $q->where('name', 'Student'));
            })
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'student_id_number', 'year_level']);

        return response()->json([
            'members' => $members,
        ]);
    }

    public function eligibleAdvisers(
        Request $request,
        Organization $organization,
        OfficerRosterService $service
    ): JsonResponse {
        $this->authorizeRosterGovernanceAccess($request, $organization, $service);

        $advisers = User::query()
            ->where(function ($query): void {
                $query->where('user_type', '!=', 'student')
                    ->orWhereNull('user_type');
            })
            ->whereDoesntHave('roles', fn ($q) => $q->where('name', 'Student'))
            ->where('email', 'not like', 's.%')
            ->orderBy('name')
            ->get(['id', 'name', 'email'])
            ->filter(fn (User $user): bool => $service->isEligibleAdviser($user, $organization))
            ->values();

        return response()->json([
            'advisers' => $advisers,
        ]);
    }

    private function authorizeRosterGovernanceAccess(
        Request $request,
        Organization $organization,
        OfficerRosterService $service,
        ?OrganizationOfficerRoster $roster = null
    ): void {
        $user = $request->user();

        abort_unless($user, 403);

        if ($user->hasRole('Super Admin (OSA)')) {
            return;
        }

        if ($service->canPrepareRoster($user, $organization) || $service->canRequestOfficerChange($user, $organization)) {
            return;
        }

        if ($roster && $service->canVerifyRoster($user, $roster)) {
            return;
        }

        abort(403, 'Unauthorized. Officer roster governance is restricted to OSA and the exact organization authority.');
    }

    private function authorizeChangeRequestAccess(
        Request $request,
        Organization $organization,
        OfficerRosterService $service
    ): void {
        $user = $request->user();

        abort_unless($user, 403);

        if ($user->hasRole('Super Admin (OSA)') || $service->canRequestOfficerChange($user, $organization)) {
            return;
        }

        abort(403, 'Unauthorized. Officer change requests are restricted to OSA and the exact organization authority.');
    }

    private function abortIfRosterDoesNotBelongToOrganization(
        OrganizationOfficerRoster $roster,
        Organization $organization
    ): void {
        abort_unless($roster->organization_id === $organization->id, 404);
    }

    private function abortIfChangeRequestDoesNotBelongToOrganization(
        OfficerChangeRequest $changeRequest,
        Organization $organization
    ): void {
        abort_unless($changeRequest->organization_id === $organization->id, 404);
        abort_unless($changeRequest->roster?->organization_id === $organization->id, 404);
    }

    private function serializeRoster(OrganizationOfficerRoster $roster): array
    {
        return [
            'id' => $roster->id,
            'organization_id' => $roster->organization_id,
            'school_year_id' => $roster->school_year_id,
            'status' => $roster->status,
            'evidence_file_path' => $roster->evidence_file_path,
            'evidence_file_name' => $roster->evidence_file_name,
            'attestation_text' => $roster->attestation_text,
            'submitted_by' => $roster->submitted_by,
            'submitted_at' => $roster->submitted_at?->toIso8601String(),
            'verified_by' => $roster->verified_by,
            'verified_at' => $roster->verified_at?->toIso8601String(),
            'verification_remarks' => $roster->verification_remarks,
            'rejection_remarks' => $roster->rejection_remarks,
            'officers' => $roster->officers->map(fn (OrganizationOfficer $officer): array => [
                'id' => $officer->id,
                'user_id' => $officer->user_id,
                'user_name' => $officer->user?->name,
                'user_email' => $officer->user?->email,
                'position_key' => $officer->position_key,
                'position_title' => $officer->position_title,
                'status' => $officer->status,
                'effective_start' => $officer->effective_start?->toDateString(),
                'effective_end' => $officer->effective_end?->toDateString(),
            ]),
        ];
    }

    private function serializeChangeRequest(OfficerChangeRequest $cr): array
    {
        return [
            'id' => $cr->id,
            'organization_id' => $cr->organization_id,
            'officer_roster_id' => $cr->officer_roster_id,
            'old_officer_id' => $cr->old_officer_id,
            'old_officer' => $cr->oldOfficer ? [
                'id' => $cr->oldOfficer->id,
                'user_id' => $cr->oldOfficer->user_id,
                'user_name' => $cr->oldOfficer->user?->name,
                'position_key' => $cr->oldOfficer->position_key,
                'position_title' => $cr->oldOfficer->position_title,
                'status' => $cr->oldOfficer->status,
            ] : null,
            'new_user_id' => $cr->new_user_id,
            'new_user' => $cr->newUser ? [
                'id' => $cr->newUser->id,
                'name' => $cr->newUser->name,
                'email' => $cr->newUser->email,
            ] : null,
            'created_officer_id' => $cr->created_officer_id,
            'request_type' => $cr->request_type,
            'position_key' => $cr->position_key,
            'position_title' => $cr->position_title,
            'status' => $cr->status,
            'reason' => $cr->reason,
            'evidence_file_path' => $cr->evidence_file_path,
            'evidence_file_name' => $cr->evidence_file_name,
            'requested_by' => $cr->requested_by,
            'requested_by_name' => $cr->requestedBy?->name,
            'requested_at' => $cr->requested_at?->toIso8601String(),
            'decided_by' => $cr->decided_by,
            'decided_by_name' => $cr->decidedBy?->name,
            'decided_at' => $cr->decided_at?->toIso8601String(),
            'decision_remarks' => $cr->decision_remarks,
        ];
    }
}
