<?php

namespace Database\Factories;

use App\Models\Venue;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Venue>
 */
class VenueFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company().' Hall',
            'campus_location' => fake()->unique()->streetName(),
            'college_id' => null,
            'managing_office' => fake()->company(),
            'inquiry_instructions' => 'Contact the managing office before recording confirmation in EAES.',
            'is_active' => true,
            'retired_at' => null,
        ];
    }

    public function retired(): static
    {
        return $this->state(fn (): array => ['is_active' => false, 'retired_at' => now()]);
    }
}
