<?php

namespace App\Services;

use App\Models\AdminAssignment;
use App\Models\AuditLog;
use App\Models\OfficerChangeRequest;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\OrganizationOfficer;
use App\Models\OrganizationOfficerRoster;
use App\Models\OsaAuthorityOverride;
use App\Models\SchoolYear;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OfficerRosterService
{
    public function __construct(private readonly OfficerAuthorityProjectionService $authorityProjections) {}

    /**
     * Determine if a user is authorized to prepare/draft/submit an officer roster.
     */
    public function canPrepareRoster(User $user, Organization $organization): bool
    {
        if ($user->hasRole('Super Admin (OSA)')) {
            return true;
        }

        if ($this->isOutgoingPresidentOrSecretary($user, $organization)) {
            return true;
        }

        if ($this->isEligibleAdviser($user, $organization) && ! $this->outgoingOfficersAvailable($organization)) {
            return true;
        }

        return false;
    }

    /**
     * Check if the user is an outgoing verified President or Secretary.
     */
    public function isOutgoingPresidentOrSecretary(User $user, Organization $organization): bool
    {
        // 1. Check verified roster for the organization
        $verifiedRosterOfficer = OrganizationOfficer::query()
            ->where('user_id', $user->id)
            ->where('status', OrganizationOfficer::STATUS_ACTIVE)
            ->whereIn('position_key', [OrganizationOfficer::POSITION_PRESIDENT, OrganizationOfficer::POSITION_SECRETARY])
            ->whereHas('roster', function ($query) use ($organization): void {
                $query->where('organization_id', $organization->id)
                    ->where('status', OrganizationOfficerRoster::STATUS_VERIFIED);
            })
            ->exists();

        if ($verifiedRosterOfficer) {
            return true;
        }

        // 2. Check active admin assignments
        return $user->adminAssignments()
            ->where('organization_id', $organization->id)
            ->where('status', AdminAssignment::STATUS_ACTIVE)
            ->where(function ($query): void {
                $query->where('is_primary_admin', true)
                    ->orWhere('position_title', 'like', '%president%')
                    ->orWhere('position_title', 'like', '%secretary%')
                    ->orWhereIn('role_name', ['Society Admin', 'USG Admin', 'LSG Admin', 'Academic Unit Admin']);
            })
            ->exists();
    }

    /**
     * Check if outgoing President or Secretary is currently available for the organization.
     */
    public function outgoingOfficersAvailable(Organization $organization): bool
    {
        $hasVerifiedOfficers = OrganizationOfficer::query()
            ->where('status', OrganizationOfficer::STATUS_ACTIVE)
            ->whereIn('position_key', [OrganizationOfficer::POSITION_PRESIDENT, OrganizationOfficer::POSITION_SECRETARY])
            ->whereHas('roster', function ($query) use ($organization): void {
                $query->where('organization_id', $organization->id)
                    ->where('status', OrganizationOfficerRoster::STATUS_VERIFIED);
            })
            ->exists();

        if ($hasVerifiedOfficers) {
            return true;
        }

        return AdminAssignment::query()
            ->where('organization_id', $organization->id)
            ->where('status', AdminAssignment::STATUS_ACTIVE)
            ->where(function ($query): void {
                $query->where('is_primary_admin', true)
                    ->orWhere('position_title', 'like', '%president%')
                    ->orWhere('position_title', 'like', '%secretary%')
                    ->orWhereIn('role_name', ['Society Admin', 'USG Admin', 'LSG Admin', 'Academic Unit Admin']);
            })
            ->exists();
    }

    /**
     * Check if a user is an eligible institutional Adviser.
     */
    public function isEligibleAdviser(User $user, ?Organization $organization = null): bool
    {
        if ($user->user_type === 'student' || Str::startsWith(Str::lower($user->email), 's.')) {
            return false;
        }

        if ($user->hasRole('Student') && ! $user->hasRole('Faculty')) {
            return false;
        }

        if ($organization) {
            $isAssignedAdviser = OrganizationOfficer::query()
                ->where('user_id', $user->id)
                ->where('position_key', OrganizationOfficer::POSITION_ADVISER)
                ->whereHas('roster', function ($query) use ($organization): void {
                    $query->where('organization_id', $organization->id)
                        ->where('status', OrganizationOfficerRoster::STATUS_VERIFIED);
                })
                ->exists();

            if ($isAssignedAdviser) {
                return true;
            }
        }

        return $user->hasRole('Faculty')
            || $user->user_type === 'faculty_or_staff'
            || $user->employeeProfile()->exists()
            || ! Str::startsWith(Str::lower($user->email), 's.');
    }

    /**
     * Validate that the School Year is open for roster submission.
     */
    public function assertSchoolYearOpen(SchoolYear $schoolYear): void
    {
        if ($schoolYear->status === SchoolYear::STATUS_CLOSED) {
            throw ValidationException::withMessages([
                'school_year' => 'Rosters cannot be prepared or submitted for a closed School Year.',
            ]);
        }

        $activeSchoolYear = SchoolYear::query()
            ->where('status', SchoolYear::STATUS_ACTIVE)
            ->first();

        $isActive = $schoolYear->status === SchoolYear::STATUS_ACTIVE;
        $isTransitionOpen = $activeSchoolYear?->isTransitionOpen() ?? false;

        if (! $isActive && ! $isTransitionOpen) {
            throw ValidationException::withMessages([
                'school_year' => 'Rosters can only be prepared for an active School Year or during an open transition period.',
            ]);
        }
    }

    /**
     * Save a roster draft (partial allowed, status remains draft).
     *
     * @param  array{officers?: array<int, array{user_id: string, position_key: string, position_title?: ?string}>, attestation_text?: ?string}  $data
     */
    public function saveDraft(
        Organization $organization,
        SchoolYear $schoolYear,
        array $data,
        User $actor,
        ?UploadedFile $evidenceFile = null
    ): OrganizationOfficerRoster {
        $this->authorizePreparer($actor, $organization);
        $this->assertSchoolYearOpen($schoolYear);

        return DB::transaction(function () use ($organization, $schoolYear, $data, $actor, $evidenceFile): OrganizationOfficerRoster {
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

            if (isset($data['attestation_text'])) {
                $roster->attestation_text = $data['attestation_text'];
            }

            if ($evidenceFile) {
                $path = $evidenceFile->store('officer_rosters', 'local');
                $roster->evidence_file_path = $path;
                $roster->evidence_file_name = $evidenceFile->getClientOriginalName();
                $roster->evidence_metadata = [
                    'size' => $evidenceFile->getSize(),
                    'mime' => $evidenceFile->getMimeType(),
                    'uploaded_at' => now()->toIso8601String(),
                ];
            }

            $roster->save();

            if (isset($data['officers']) && is_array($data['officers'])) {
                $this->syncOfficers($roster, $organization, $data['officers'], isDraft: true);
            }

            return $roster->load('officers.user');
        });
    }

    /**
     * Submit an officer roster (enforces all submission gates).
     *
     * @param  array{officers?: array<int, array{user_id: string, position_key: string, position_title?: ?string}>, attestation_text?: ?string}  $data
     */
    public function submit(
        Organization $organization,
        SchoolYear $schoolYear,
        array $data,
        User $actor,
        ?UploadedFile $evidenceFile = null
    ): OrganizationOfficerRoster {
        $this->authorizePreparer($actor, $organization);
        $this->assertSchoolYearOpen($schoolYear);

        return DB::transaction(function () use ($organization, $schoolYear, $data, $actor, $evidenceFile): OrganizationOfficerRoster {
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
            }

            if (isset($data['attestation_text'])) {
                $roster->attestation_text = $data['attestation_text'];
            }

            if ($evidenceFile) {
                $path = $evidenceFile->store('officer_rosters', 'local');
                $roster->evidence_file_path = $path;
                $roster->evidence_file_name = $evidenceFile->getClientOriginalName();
                $roster->evidence_metadata = [
                    'size' => $evidenceFile->getSize(),
                    'mime' => $evidenceFile->getMimeType(),
                    'uploaded_at' => now()->toIso8601String(),
                ];
            }

            $officersList = $data['officers'] ?? null;
            if ($officersList !== null && is_array($officersList)) {
                $roster->save();
                $this->syncOfficers($roster, $organization, $officersList, isDraft: false);
            } else {
                $roster->loadMissing('officers.user');
                $this->validateExistingOfficers($roster, $organization);
            }

            // Gate: Supporting evidence or attestation upload is required prior to submission
            if (empty($roster->evidence_file_path) && empty($roster->attestation_text)) {
                throw ValidationException::withMessages([
                    'evidence' => 'Supporting evidence file or official attestation statement is required prior to submission.',
                ]);
            }

            // Gate: Required roles (President, Secretary, Adviser)
            $roster->load('officers');
            $hasPresident = $roster->officers->contains(fn ($o) => $o->position_key === OrganizationOfficer::POSITION_PRESIDENT && $o->status === OrganizationOfficer::STATUS_ACTIVE);
            $hasSecretary = $roster->officers->contains(fn ($o) => $o->position_key === OrganizationOfficer::POSITION_SECRETARY && $o->status === OrganizationOfficer::STATUS_ACTIVE);
            $hasAdviser = $roster->officers->contains(fn ($o) => $o->position_key === OrganizationOfficer::POSITION_ADVISER && $o->status === OrganizationOfficer::STATUS_ACTIVE);

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

    /**
     * Synchronize and validate officers on a roster.
     *
     * @param  array<int, array{user_id: string, position_key: string, position_title?: ?string}>  $officersData
     */
    private function syncOfficers(
        OrganizationOfficerRoster $roster,
        Organization $organization,
        array $officersData,
        bool $isDraft
    ): void {
        $roster->officers()->delete();

        foreach ($officersData as $index => $officer) {
            $user = User::find($officer['user_id'] ?? null);
            if (! $user) {
                throw ValidationException::withMessages([
                    "officers.{$index}.user_id" => 'Selected user was not found.',
                ]);
            }

            $positionKey = strtolower(trim((string) ($officer['position_key'] ?? '')));
            $positionTitle = trim((string) ($officer['position_title'] ?? ucfirst(str_replace('_', ' ', $positionKey))));

            // Eligibility check: Adviser must be an eligible institutional account
            if ($positionKey === OrganizationOfficer::POSITION_ADVISER) {
                if (! $this->isEligibleAdviser($user, $organization)) {
                    throw ValidationException::withMessages([
                        "officers.{$index}.user_id" => 'The Adviser must be an eligible institutional faculty or staff account, not a student.',
                    ]);
                }
            } else {
                // Student officers (President, Secretary, etc.) must be active registered members
                if ($user->user_type !== null && $user->user_type !== 'student' && ! $user->hasRole('Student')) {
                    throw ValidationException::withMessages([
                        "officers.{$index}.user_id" => "The {$positionTitle} must be a student account.",
                    ]);
                }

                $isMember = OrganizationMembership::query()
                    ->where('organization_id', $organization->id)
                    ->where('user_id', $user->id)
                    ->where('status', OrganizationMembership::STATUS_ACTIVE)
                    ->exists();

                if (! $isMember) {
                    throw ValidationException::withMessages([
                        "officers.{$index}.user_id" => "The selected {$positionTitle} must be an active registered member of {$organization->name}.",
                    ]);
                }
            }

            $roster->officers()->create([
                'user_id' => $user->id,
                'position_key' => $positionKey,
                'position_title' => $positionTitle,
                'status' => OrganizationOfficer::STATUS_ACTIVE,
                'effective_start' => now()->toDateString(),
            ]);
        }
    }

    private function validateExistingOfficers(OrganizationOfficerRoster $roster, Organization $organization): void
    {
        foreach ($roster->officers as $officer) {
            $user = $officer->user;
            if ($officer->position_key === OrganizationOfficer::POSITION_ADVISER) {
                if (! $this->isEligibleAdviser($user, $organization)) {
                    throw ValidationException::withMessages([
                        'officers' => 'The Adviser must be an eligible institutional faculty or staff account, not a student.',
                    ]);
                }
            } else {
                $isMember = OrganizationMembership::query()
                    ->where('organization_id', $organization->id)
                    ->where('user_id', $user->id)
                    ->where('status', OrganizationMembership::STATUS_ACTIVE)
                    ->exists();

                if (! $isMember) {
                    throw ValidationException::withMessages([
                        'officers' => "Officer {$user->name} must be an active registered member of {$organization->name}.",
                    ]);
                }
            }
        }
    }

    private function authorizePreparer(User $actor, Organization $organization): void
    {
        if ($actor->hasRole('Super Admin (OSA)')) {
            return;
        }

        if ($this->isOutgoingPresidentOrSecretary($actor, $organization)) {
            return;
        }

        if ($this->isEligibleAdviser($actor, $organization)) {
            if ($this->outgoingOfficersAvailable($organization)) {
                throw ValidationException::withMessages([
                    'preparer' => 'Adviser can only initiate roster preparation when outgoing officers are unavailable.',
                ]);
            }

            return;
        }

        abort(403, 'Unauthorized. Only outgoing verified officers or eligible advisers when officers are unavailable may prepare an officer roster.');
    }

    /**
     * Check if a user is authorized to verify or request changes on an officer roster.
     */
    public function canVerifyRoster(User $user, OrganizationOfficerRoster $roster): bool
    {
        if ($user->hasRole('Super Admin (OSA)')) {
            return true;
        }

        if ($this->isRosterLocked($roster)) {
            return false;
        }

        $roster->loadMissing('officers');

        $isDesignatedAdviser = $roster->officers
            ->where('position_key', OrganizationOfficer::POSITION_ADVISER)
            ->where('user_id', $user->id)
            ->isNotEmpty();

        if ($isDesignatedAdviser) {
            return true;
        }

        return OrganizationOfficer::query()
            ->where('user_id', $user->id)
            ->where('position_key', OrganizationOfficer::POSITION_ADVISER)
            ->where('status', OrganizationOfficer::STATUS_ACTIVE)
            ->whereHas('roster', function ($query) use ($roster): void {
                $query->where('organization_id', $roster->organization_id)
                    ->where('status', OrganizationOfficerRoster::STATUS_VERIFIED);
            })
            ->exists();
    }

    /**
     * Authorize that the user can act as adviser for the given roster.
     */
    public function authorizeAdviser(User $user, OrganizationOfficerRoster $roster): void
    {
        if (! $this->canVerifyRoster($user, $roster)) {
            abort(403, 'Unauthorized. Only the designated organization adviser or OSA may verify or request changes on this officer roster.');
        }
    }

    /**
     * Adviser verifies the submitted officer roster, turning it into official governance.
     */
    public function verifyRoster(OrganizationOfficerRoster $roster, User $adviser, ?string $remarks = null): OrganizationOfficerRoster
    {
        $this->authorizeAdviser($adviser, $roster);

        if ($roster->status !== OrganizationOfficerRoster::STATUS_SUBMITTED) {
            throw ValidationException::withMessages([
                'roster' => 'Only submitted officer rosters can be verified.',
            ]);
        }

        $this->assertSchoolYearOpen($roster->schoolYear);

        return DB::transaction(function () use ($roster, $adviser, $remarks): OrganizationOfficerRoster {
            // Supersede any existing verified roster for this organization & school year
            $previousVerifiedRosters = OrganizationOfficerRoster::query()
                ->where('organization_id', $roster->organization_id)
                ->where('school_year_id', $roster->school_year_id)
                ->where('id', '!=', $roster->id)
                ->where('status', OrganizationOfficerRoster::STATUS_VERIFIED)
                ->get();

            foreach ($previousVerifiedRosters as $prevRoster) {
                $prevRoster->update(['status' => OrganizationOfficerRoster::STATUS_SUPERSEDED]);
                $prevRoster->officers()->where('status', OrganizationOfficer::STATUS_ACTIVE)->update([
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

            // Set effective start on active officers if not already set
            foreach ($roster->officers as $officer) {
                if ($officer->status === OrganizationOfficer::STATUS_ACTIVE && ! $officer->effective_start) {
                    $officer->effective_start = now()->toDateString();
                    $officer->save();
                }
            }

            // Project official governance authority into admin_assignments
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

    /**
     * Adviser requests changes on the submitted officer roster with mandatory remarks.
     */
    public function requestChanges(OrganizationOfficerRoster $roster, User $adviser, string $remarks): OrganizationOfficerRoster
    {
        $this->authorizeAdviser($adviser, $roster);

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

    /**
     * Project verified officer governance into admin assignments.
     */
    public function projectOfficerAuthority(OrganizationOfficerRoster $roster, User $adviser): void
    {
        $this->authorityProjections->synchronize($roster, $adviser);
    }

    /**
     * Check if a user is authorized to submit an officer change request.
     */
    public function canRequestOfficerChange(User $user, Organization $organization): bool
    {
        if ($user->hasRole('Super Admin (OSA)')) {
            return true;
        }

        $isVerifiedOfficer = OrganizationOfficer::query()
            ->where('user_id', $user->id)
            ->where('status', OrganizationOfficer::STATUS_ACTIVE)
            ->whereIn('position_key', [
                OrganizationOfficer::POSITION_PRESIDENT,
                OrganizationOfficer::POSITION_SECRETARY,
                OrganizationOfficer::POSITION_ADVISER,
            ])
            ->whereHas('roster', function ($query) use ($organization): void {
                $query->where('organization_id', $organization->id)
                    ->where('status', OrganizationOfficerRoster::STATUS_VERIFIED);
            })
            ->exists();

        if ($isVerifiedOfficer) {
            return true;
        }

        return $this->isOutgoingPresidentOrSecretary($user, $organization);
    }

    /**
     * Authorize change requester.
     */
    public function authorizeChangeRequester(User $user, Organization $organization): void
    {
        if (! $this->canRequestOfficerChange($user, $organization)) {
            abort(403, 'Unauthorized. Only active verified officers or advisers may submit an officer change request.');
        }
    }

    /**
     * Create an officer change request (mid-year resignation, replacement, revocation, correction).
     *
     * @param  array{old_officer_id: string, request_type: string, new_user_id?: ?string, position_title?: ?string, reason: string}  $data
     */
    public function createChangeRequest(
        Organization $organization,
        array $data,
        User $actor,
        ?UploadedFile $evidenceFile = null
    ): OfficerChangeRequest {
        $this->authorizeChangeRequester($actor, $organization);

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

        $requestType = strtolower(trim((string) ($data['request_type'] ?? '')));
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

            if ($oldOfficer->position_key === OrganizationOfficer::POSITION_ADVISER) {
                if (! $this->isEligibleAdviser($newUser, $organization)) {
                    throw ValidationException::withMessages([
                        'new_user_id' => 'The replacement Adviser must be an eligible institutional faculty or staff account, not a student.',
                    ]);
                }
            } else {
                if ($newUser->user_type !== null && $newUser->user_type !== 'student' && ! $newUser->hasRole('Student')) {
                    throw ValidationException::withMessages([
                        'new_user_id' => 'The replacement officer must be a student account.',
                    ]);
                }

                $isMember = OrganizationMembership::query()
                    ->where('organization_id', $organization->id)
                    ->where('user_id', $newUser->id)
                    ->where('status', OrganizationMembership::STATUS_ACTIVE)
                    ->exists();

                if (! $isMember) {
                    throw ValidationException::withMessages([
                        'new_user_id' => "The replacement officer must be an active registered member of {$organization->name}.",
                    ]);
                }
            }
        }

        $evidenceFilePath = null;
        $evidenceFileName = null;
        $evidenceMetadata = null;

        if ($evidenceFile) {
            $evidenceFilePath = $evidenceFile->store('officer_change_requests', 'local');
            $evidenceFileName = $evidenceFile->getClientOriginalName();
            $evidenceMetadata = [
                'size' => $evidenceFile->getSize(),
                'mime' => $evidenceFile->getMimeType(),
                'uploaded_at' => now()->toIso8601String(),
            ];
        }

        return DB::transaction(function () use (
            $organization,
            $roster,
            $oldOfficer,
            $newUserId,
            $requestType,
            $data,
            $evidenceFilePath,
            $evidenceFileName,
            $evidenceMetadata,
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
                'evidence_file_path' => $evidenceFilePath,
                'evidence_file_name' => $evidenceFileName,
                'evidence_metadata' => $evidenceMetadata,
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

    /**
     * Adviser approves officer change request, transitioning prior officer status while preserving history.
     */
    public function approveChangeRequest(
        OfficerChangeRequest $changeRequest,
        User $adviser,
        ?string $remarks = null
    ): OfficerChangeRequest {
        $changeRequest->loadMissing('roster.organization', 'oldOfficer');
        $this->authorizeAdviser($adviser, $changeRequest->roster);

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
                $this->projectOfficerAuthority($changeRequest->roster, $adviser);
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

    /**
     * Adviser rejects officer change request with mandatory remarks.
     */
    public function rejectChangeRequest(
        OfficerChangeRequest $changeRequest,
        User $adviser,
        string $remarks
    ): OfficerChangeRequest {
        $changeRequest->loadMissing('roster.organization');
        $this->authorizeAdviser($adviser, $changeRequest->roster);

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

    private function isRosterLocked(OrganizationOfficerRoster $roster): bool
    {
        return OsaAuthorityOverride::query()
            ->currentlyEffective()
            ->where('action', OsaAuthorityOverride::ACTION_LOCK)
            ->where('organization_id', $roster->organization_id)
            ->where('school_year_id', $roster->school_year_id)
            ->exists();
    }
}
