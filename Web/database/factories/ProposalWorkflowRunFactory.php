<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\ProposalProcessTemplateVersion;
use App\Models\ProposalWorkflowRun;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProposalWorkflowRun>
 */
class ProposalWorkflowRunFactory extends Factory
{
    protected $model = ProposalWorkflowRun::class;

    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'template_version_id' => ProposalProcessTemplateVersion::factory()->published(),
            'status' => ProposalWorkflowRun::STATUS_IN_PROGRESS,
            'started_by' => User::factory(),
            'started_at' => now(),
            'submitted_at' => now(),
            'completed_at' => null,
            'cancelled_at' => null,
            'metadata' => null,
        ];
    }
}
