<?php

namespace Database\Factories;

use App\Models\College;
use App\Models\Department;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Department>
 */
class DepartmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'college_id' => College::factory(),
            'name' => 'Department of '.fake()->unique()->words(3, true),
            'code' => Str::upper(fake()->unique()->lexify('????')),
            'status' => 'active',
        ];
    }
}
