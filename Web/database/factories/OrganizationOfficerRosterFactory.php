<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\OrganizationOfficerRoster;
use App\Models\SchoolYear;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrganizationOfficerRoster>
 */
class OrganizationOfficerRosterFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'school_year_id' => SchoolYear::factory(),
            'status' => OrganizationOfficerRoster::STATUS_DRAFT,
            'evidence_file_path' => null,
            'evidence_file_name' => null,
            'attestation_text' => null,
            'evidence_metadata' => null,
            'submitted_by' => null,
            'submitted_at' => null,
            'verified_by' => null,
            'verified_at' => null,
            'verification_remarks' => null,
            'rejection_remarks' => null,
            'created_by' => User::factory(),
        ];
    }

    public function submitted(User $submitter): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => OrganizationOfficerRoster::STATUS_SUBMITTED,
            'submitted_by' => $submitter->id,
            'submitted_at' => now(),
            'attestation_text' => 'Official roster submission attestation statement.',
        ]);
    }

    public function verified(User $verifier): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => OrganizationOfficerRoster::STATUS_VERIFIED,
            'verified_by' => $verifier->id,
            'verified_at' => now(),
            'verification_remarks' => 'Verified and approved by organization adviser.',
        ]);
    }
}
