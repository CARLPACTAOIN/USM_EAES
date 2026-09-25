<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveOfficerRosterDraftRequest;
use App\Http\Requests\SubmitOfficerRosterRequest;
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

    public function eligibleMembers(Request $request, Organization $organization): JsonResponse
    {
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
}
