<?php

namespace Database\Factories;

use App\Models\ProposalProcessTemplate;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProposalProcessTemplate>
 */
class ProposalProcessTemplateFactory extends Factory
{
    protected $model = ProposalProcessTemplate::class;

    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true).' Workflow',
            'description' => fake()->sentence(),
            'status' => ProposalProcessTemplate::STATUS_ACTIVE,
            'created_by' => User::factory(),
            'archived_at' => null,
        ];
    }
}
