<?php

namespace Database\Factories;

use App\Models\College;
use App\Models\University;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<College>
 */
class CollegeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'university_id' => University::factory(),
            'name' => fake()->unique()->company().' College',
            'code' => Str::upper(fake()->unique()->lexify('???')),
        ];
    }
}
