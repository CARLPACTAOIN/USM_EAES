<?php

namespace Database\Factories;

use App\Models\OrganizationOfficer;
use App\Models\OrganizationOfficerRoster;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrganizationOfficer>
 */
class OrganizationOfficerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'officer_roster_id' => OrganizationOfficerRoster::factory(),
            'user_id' => User::factory(),
            'position_key' => OrganizationOfficer::POSITION_PRESIDENT,
            'position_title' => 'President',
            'status' => OrganizationOfficer::STATUS_ACTIVE,
            'effective_start' => now()->toDateString(),
            'effective_end' => null,
            'replacement_officer_id' => null,
            'metadata' => null,
        ];
    }

    public function president(): static
    {
        return $this->state(fn (array $attributes): array => [
            'position_key' => OrganizationOfficer::POSITION_PRESIDENT,
            'position_title' => 'President',
        ]);
    }

    public function secretary(): static
    {
        return $this->state(fn (array $attributes): array => [
            'position_key' => OrganizationOfficer::POSITION_SECRETARY,
            'position_title' => 'Secretary',
        ]);
    }

    public function adviser(): static
    {
        return $this->state(fn (array $attributes): array => [
            'position_key' => OrganizationOfficer::POSITION_ADVISER,
            'position_title' => 'Faculty Adviser',
        ]);
    }
}
