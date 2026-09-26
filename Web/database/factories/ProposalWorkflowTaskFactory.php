<?php

namespace Database\Factories;

use App\Models\ProposalProcessStep;
use App\Models\ProposalWorkflowRun;
use App\Models\ProposalWorkflowTask;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProposalWorkflowTask>
 */
class ProposalWorkflowTaskFactory extends Factory
{
    protected $model = ProposalWorkflowTask::class;

    public function definition(): array
    {
        return [
            'workflow_run_id' => ProposalWorkflowRun::factory(),
            'process_step_id' => ProposalProcessStep::factory(),
            'status' => ProposalWorkflowTask::STATUS_AVAILABLE,
            'display_order' => 1,
            'step_key' => fake()->slug(2),
            'step_type' => ProposalProcessStep::TYPE_APPROVAL,
            'title' => fake()->words(3, true),
            'description' => null,
            'assigned_user_id' => null,
            'assigned_role' => null,
            'assigned_scope' => null,
            'available_at' => now(),
            'completed_at' => null,
            'decision' => null,
            'remarks' => null,
            'decided_by' => null,
            'decided_at' => null,
            'metadata' => null,
        ];
    }
}
