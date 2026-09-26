<?php

namespace Database\Factories;

use App\Models\ProposalProcessTemplate;
use App\Models\ProposalProcessTemplateVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProposalProcessTemplateVersion>
 */
class ProposalProcessTemplateVersionFactory extends Factory
{
    protected $model = ProposalProcessTemplateVersion::class;

    public function definition(): array
    {
        return [
            'template_id' => ProposalProcessTemplate::factory(),
            'version_number' => 1,
            'status' => ProposalProcessTemplateVersion::STATUS_DRAFT,
            'summary' => fake()->sentence(),
            'published_at' => null,
            'published_by' => null,
            'retired_at' => null,
            'retired_by' => null,
        ];
    }

    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ProposalProcessTemplateVersion::STATUS_PUBLISHED,
            'published_at' => now(),
            'published_by' => User::factory(),
        ]);
    }

    public function retired(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ProposalProcessTemplateVersion::STATUS_RETIRED,
            'retired_at' => now(),
            'retired_by' => User::factory(),
        ]);
    }
}
