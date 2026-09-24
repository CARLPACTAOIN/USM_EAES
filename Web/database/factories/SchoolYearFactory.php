<?php

namespace Database\Factories;

use App\Models\SchoolYear;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SchoolYear>
 */
class SchoolYearFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startYear = fake()->unique()->numberBetween(2030, 2099);

        return [
            'label' => $startYear.'-'.($startYear + 1),
            'starts_on' => "{$startYear}-07-01",
            'ends_on' => ($startYear + 1).'-06-30',
            'status' => SchoolYear::STATUS_DRAFT,
        ];
    }

    public function active(User $actor): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => SchoolYear::STATUS_ACTIVE,
            'activated_by' => $actor->id,
            'activated_at' => now(),
        ]);
    }

    public function closed(User $actor): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => SchoolYear::STATUS_CLOSED,
            'closed_by' => $actor->id,
            'closed_at' => now(),
        ]);
    }
}
