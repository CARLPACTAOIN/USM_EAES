<?php

namespace Database\Factories;

use App\Models\OfficerChangeRequest;
use App\Models\Organization;
use App\Models\OrganizationOfficer;
use App\Models\OrganizationOfficerRoster;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OfficerChangeRequest>
 */
class OfficerChangeRequestFactory extends Factory
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
            'officer_roster_id' => OrganizationOfficerRoster::factory(),
            'old_officer_id' => OrganizationOfficer::factory(),
            'new_user_id' => User::factory(),
            'created_officer_id' => null,
            'request_type' => OfficerChangeRequest::TYPE_REPLACEMENT,
            'position_key' => OrganizationOfficer::POSITION_PRESIDENT,
            'position_title' => 'President',
            'status' => OfficerChangeRequest::STATUS_PENDING,
            'reason' => 'Officer stepping down due to academic workload.',
            'evidence_file_path' => null,
            'evidence_file_name' => null,
            'evidence_metadata' => null,
            'requested_by' => User::factory(),
            'requested_at' => now(),
            'decided_by' => null,
            'decided_at' => null,
            'decision_remarks' => null,
        ];
    }

    public function resignation(): static
    {
        return $this->state(fn (array $attributes): array => [
            'request_type' => OfficerChangeRequest::TYPE_RESIGNATION,
            'new_user_id' => null,
            'reason' => 'Officer resignation submitted.',
        ]);
    }

    public function revocation(): static
    {
        return $this->state(fn (array $attributes): array => [
            'request_type' => OfficerChangeRequest::TYPE_REVOCATION,
            'new_user_id' => null,
            'reason' => 'Officer authority revoked due to disciplinary action.',
        ]);
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => OfficerChangeRequest::STATUS_APPROVED,
            'decided_by' => User::factory(),
            'decided_at' => now(),
            'decision_remarks' => 'Change request approved by adviser.',
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => OfficerChangeRequest::STATUS_REJECTED,
            'decided_by' => User::factory(),
            'decided_at' => now(),
            'decision_remarks' => 'Change request rejected due to incomplete requirements.',
        ]);
    }
}
