<?php

namespace Database\Factories;

use App\Models\OsaAuthorityOverride;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OsaAuthorityOverride>
 */
class OsaAuthorityOverrideFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'action' => OsaAuthorityOverride::ACTION_GRANT,
            'status' => OsaAuthorityOverride::STATUS_ACTIVE,
            'role_name' => 'Society Admin',
            'academic_year' => now()->year.'-'.(now()->year + 1),
            'position_title' => 'OSA override',
            'reason' => fake()->sentence(12),
            'starts_at' => now(),
            'actor_id' => User::factory(),
            'acted_at' => now(),
        ];
    }
}
