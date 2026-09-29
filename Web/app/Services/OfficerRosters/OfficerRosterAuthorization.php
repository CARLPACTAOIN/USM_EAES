<?php

namespace App\Services\OfficerRosters;

use App\Models\AdminAssignment;
use App\Models\Organization;
use App\Models\OrganizationOfficer;
use App\Models\OrganizationOfficerRoster;
use App\Models\OsaAuthorityOverride;
use App\Models\SchoolYear;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OfficerRosterAuthorization
{
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

    public function isOutgoingPresidentOrSecretary(User $user, Organization $organization): bool
    {
        if ($this->hasActiveVerifiedPresidentOrSecretary($organization, $user)) {
            return true;
        }

        return $this->activeAdminAuthorityQuery($organization)
            ->where('user_id', $user->id)
            ->exists();
    }

    public function outgoingOfficersAvailable(Organization $organization): bool
    {
        if ($this->hasActiveVerifiedPresidentOrSecretary($organization)) {
            return true;
        }

        return $this->activeAdminAuthorityQuery($organization)->exists();
    }

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

    public function authorizePreparer(User $actor, Organization $organization): void
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

    public function authorizeAdviser(User $user, OrganizationOfficerRoster $roster): void
    {
        if (! $this->canVerifyRoster($user, $roster)) {
            abort(403, 'Unauthorized. Only the designated organization adviser or OSA may verify or request changes on this officer roster.');
        }
    }

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

    public function authorizeChangeRequester(User $user, Organization $organization): void
    {
        if (! $this->canRequestOfficerChange($user, $organization)) {
            abort(403, 'Unauthorized. Only active verified officers or advisers may submit an officer change request.');
        }
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

    private function hasActiveVerifiedPresidentOrSecretary(Organization $organization, ?User $user = null): bool
    {
        return OrganizationOfficer::query()
            ->when($user, fn (Builder $query): Builder => $query->where('user_id', $user->id))
            ->where('status', OrganizationOfficer::STATUS_ACTIVE)
            ->whereIn('position_key', [OrganizationOfficer::POSITION_PRESIDENT, OrganizationOfficer::POSITION_SECRETARY])
            ->whereHas('roster', function ($query) use ($organization): void {
                $query->where('organization_id', $organization->id)
                    ->where('status', OrganizationOfficerRoster::STATUS_VERIFIED);
            })
            ->exists();
    }

    private function activeAdminAuthorityQuery(Organization $organization): Builder
    {
        return AdminAssignment::query()
            ->where('organization_id', $organization->id)
            ->where('status', AdminAssignment::STATUS_ACTIVE)
            ->where(function ($query): void {
                $query->where('is_primary_admin', true)
                    ->orWhere('position_title', 'like', '%president%')
                    ->orWhere('position_title', 'like', '%secretary%')
                    ->orWhereIn('role_name', ['Society Admin', 'USG Admin', 'LSG Admin', 'Academic Unit Admin']);
            });
    }
}
