<?php

namespace Database\Factories;

use App\Models\ProposalProcessStep;
use App\Models\ProposalProcessTemplateVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProposalProcessStep>
 */
class ProposalProcessStepFactory extends Factory
{
    protected $model = ProposalProcessStep::class;

    public function definition(): array
    {
        return [
            'template_version_id' => ProposalProcessTemplateVersion::factory(),
            'step_key' => fake()->unique()->slug(2),
            'title' => fake()->words(3, true),
            'description' => fake()->sentence(),
            'step_type' => ProposalProcessStep::TYPE_APPROVAL,
            'phase' => 'review',
            'display_order' => 1,
            'is_required' => true,
            'is_gate' => false,
            'condition_config' => null,
        ];
    }
}
