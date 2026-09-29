<?php

namespace App\Services\OfficerRosters;

use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\OrganizationOfficer;
use App\Models\OrganizationOfficerRoster;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class OfficerRosterOfficerSynchronizer
{
    public function __construct(private readonly OfficerRosterAuthorization $authorization) {}

    /**
     * @param  array<int, array{user_id: string, position_key: string, position_title?: ?string}>  $officersData
     */
    public function syncOfficers(
        OrganizationOfficerRoster $roster,
        Organization $organization,
        array $officersData
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

            $this->validateOfficerEligibility(
                $user,
                $organization,
                $positionKey,
                "officers.{$index}.user_id",
                $positionTitle
            );

            $roster->officers()->create([
                'user_id' => $user->id,
                'position_key' => $positionKey,
                'position_title' => $positionTitle,
                'status' => OrganizationOfficer::STATUS_ACTIVE,
                'effective_start' => now()->toDateString(),
            ]);
        }
    }

    public function validateExistingOfficers(OrganizationOfficerRoster $roster, Organization $organization): void
    {
        foreach ($roster->officers as $officer) {
            $user = $officer->user;
            if ($officer->position_key === OrganizationOfficer::POSITION_ADVISER) {
                if (! $this->authorization->isEligibleAdviser($user, $organization)) {
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

    public function validateReplacementOfficer(
        User $user,
        Organization $organization,
        OrganizationOfficer $oldOfficer
    ): void {
        if ($oldOfficer->position_key === OrganizationOfficer::POSITION_ADVISER) {
            if (! $this->authorization->isEligibleAdviser($user, $organization)) {
                throw ValidationException::withMessages([
                    'new_user_id' => 'The replacement Adviser must be an eligible institutional faculty or staff account, not a student.',
                ]);
            }

            return;
        }

        $this->validateOfficerEligibility(
            $user,
            $organization,
            $oldOfficer->position_key,
            'new_user_id',
            'replacement officer'
        );
    }

    private function validateOfficerEligibility(
        User $user,
        Organization $organization,
        string $positionKey,
        string $field,
        string $positionTitle
    ): void {
        if ($positionKey === OrganizationOfficer::POSITION_ADVISER) {
            if (! $this->authorization->isEligibleAdviser($user, $organization)) {
                throw ValidationException::withMessages([
                    $field => 'The Adviser must be an eligible institutional faculty or staff account, not a student.',
                ]);
            }

            return;
        }

        if ($user->user_type !== null && $user->user_type !== 'student' && ! $user->hasRole('Student')) {
            throw ValidationException::withMessages([
                $field => $field === 'new_user_id'
                    ? 'The replacement officer must be a student account.'
                    : "The {$positionTitle} must be a student account.",
            ]);
        }

        $isMember = OrganizationMembership::query()
            ->where('organization_id', $organization->id)
            ->where('user_id', $user->id)
            ->where('status', OrganizationMembership::STATUS_ACTIVE)
            ->exists();

        if (! $isMember) {
            throw ValidationException::withMessages([
                $field => $field === 'new_user_id'
                    ? "The replacement officer must be an active registered member of {$organization->name}."
                    : "The selected {$positionTitle} must be an active registered member of {$organization->name}.",
            ]);
        }
    }
}
