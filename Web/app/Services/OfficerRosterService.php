<?php

namespace App\Services;

use App\Models\AdminAssignment;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\OrganizationOfficer;
use App\Models\OrganizationOfficerRoster;
use App\Models\SchoolYear;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OfficerRosterService
{
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
}
