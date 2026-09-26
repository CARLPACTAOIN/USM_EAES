<?php

namespace App\Services;

use App\Models\Event;
use App\Models\ProposalProcessStep;
use App\Models\ProposalProcessStepDependency;
use App\Models\ProposalProcessTemplate;
use App\Models\ProposalProcessTemplateVersion;
use App\Models\ProposalTemplateApplicabilityRule;
use App\Models\ProposalWorkflowRun;
use App\Models\ProposalWorkflowTask;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ProposalWorkflowService
{
    /**
     * Bind an event proposal to an immutable published template workflow run on first submission.
     * Idempotent: returns existing run if already bound.
     */
    public function bindFirstSubmission(Event $event, User $actor): ProposalWorkflowRun
    {
        $existing = ProposalWorkflowRun::where('event_id', $event->id)->first();
        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use ($event, $actor): ProposalWorkflowRun {
            $existing = ProposalWorkflowRun::where('event_id', $event->id)->lockForUpdate()->first();
            if ($existing) {
                return $existing;
            }

            $templateVersion = $this->resolveApplicableTemplateVersion($event);
            $steps = $templateVersion->steps()->with('dependencies')->orderBy('display_order')->get();

            $run = ProposalWorkflowRun::create([
                'event_id' => $event->id,
                'template_version_id' => $templateVersion->id,
                'status' => ProposalWorkflowRun::STATUS_IN_PROGRESS,
                'started_by' => $actor->id,
                'started_at' => now(),
                'submitted_at' => now(),
            ]);

            foreach ($steps as $step) {
                $hasPrerequisites = $step->dependencies->isNotEmpty();

                $status = $hasPrerequisites
                    ? ProposalWorkflowTask::STATUS_BLOCKED
                    : ProposalWorkflowTask::STATUS_AVAILABLE;

                $config = $step->condition_config ?? [];

                ProposalWorkflowTask::create([
                    'workflow_run_id' => $run->id,
                    'process_step_id' => $step->id,
                    'status' => $status,
                    'display_order' => $step->display_order,
                    'step_key' => $step->step_key,
                    'step_type' => $step->step_type,
                    'title' => $step->title,
                    'description' => $step->description,
                    'assigned_role' => $config['assigned_role'] ?? null,
                    'assigned_scope' => $config['assigned_scope'] ?? null,
                    'available_at' => $hasPrerequisites ? null : now(),
                ]);
            }

            return $run->fresh(['tasks']);
        });
    }

    /**
     * Resolve the highest priority applicable published template version for the event.
     */
    public function resolveApplicableTemplateVersion(Event $event): ProposalProcessTemplateVersion
    {
        $event->loadMissing(['organization']);

        $now = now();
        $rules = ProposalTemplateApplicabilityRule::query()
            ->where('status', ProposalTemplateApplicabilityRule::STATUS_ACTIVE)
            ->where(function ($query) use ($now): void {
                $query->whereNull('effective_start')->orWhere('effective_start', '<=', $now);
            })
            ->where(function ($query) use ($now): void {
                $query->whereNull('effective_end')->orWhere('effective_end', '>=', $now);
            })
            ->whereHas('templateVersion', function ($query): void {
                $query->where('status', ProposalProcessTemplateVersion::STATUS_PUBLISHED)
                    ->whereHas('template', function ($tQuery): void {
                        $tQuery->where('status', ProposalProcessTemplate::STATUS_ACTIVE);
                    });
            })
            ->orderByDesc('priority')
            ->get();

        foreach ($rules as $rule) {
            if ($rule->is_default) {
                continue;
            }

            if ($rule->college_id !== null && $rule->college_id !== $event->organization?->college_id) {
                continue;
            }

            $orgType = $event->organization?->type ?? $event->organization?->organization_type;
            if ($rule->organization_type !== null && $rule->organization_type !== $orgType) {
                continue;
            }

            if ($rule->event_domain !== null && $rule->event_domain !== $event->event_domain) {
                continue;
            }

            if ($rule->location_type !== null && $rule->location_type !== $event->location_type) {
                continue;
            }

            if ($rule->activity_category !== null && $rule->activity_category !== $event->proposal_category) {
                continue;
            }

            return $rule->templateVersion;
        }

        $defaultRule = $rules->firstWhere('is_default', true);
        if ($defaultRule) {
            return $defaultRule->templateVersion;
        }

        return $this->ensureDefaultPublishedTemplate();
    }

    /**
     * Ensure a default published template version exists with standard endorsement and approval steps.
     */
    public function ensureDefaultPublishedTemplate(): ProposalProcessTemplateVersion
    {
        return DB::transaction(function (): ProposalProcessTemplateVersion {
            $template = ProposalProcessTemplate::firstOrCreate(
                ['name' => 'Standard Event Proposal Workflow'],
                [
                    'description' => 'Canonical multi-step approval workflow for student organization event proposals.',
                    'status' => ProposalProcessTemplate::STATUS_ACTIVE,
                ]
            );

            $publishedVersion = $template->publishedVersion;
            if ($publishedVersion) {
                return $publishedVersion;
            }

            $version = ProposalProcessTemplateVersion::create([
                'template_id' => $template->id,
                'version_number' => 1,
                'status' => ProposalProcessTemplateVersion::STATUS_DRAFT,
                'summary' => 'Initial standard approval workflow template version.',
            ]);

            $step1 = ProposalProcessStep::create([
                'template_version_id' => $version->id,
                'step_key' => 'adviser_endorsement',
                'title' => 'Adviser Endorsement',
                'description' => 'Endorsement by the organization faculty adviser.',
                'step_type' => ProposalProcessStep::TYPE_ENDORSEMENT,
                'phase' => 'endorsement',
                'display_order' => 1,
                'is_required' => true,
                'is_gate' => false,
                'condition_config' => ['assigned_role' => 'adviser', 'assigned_scope' => 'organization'],
            ]);

            $step2 = ProposalProcessStep::create([
                'template_version_id' => $version->id,
                'step_key' => 'dept_chair_endorsement',
                'title' => 'Department Chair Endorsement',
                'description' => 'Endorsement by the department chairperson.',
                'step_type' => ProposalProcessStep::TYPE_ENDORSEMENT,
                'phase' => 'endorsement',
                'display_order' => 2,
                'is_required' => true,
                'is_gate' => false,
                'condition_config' => ['assigned_role' => 'dept_chair', 'assigned_scope' => 'department'],
            ]);

            $step3 = ProposalProcessStep::create([
                'template_version_id' => $version->id,
                'step_key' => 'college_dean_endorsement',
                'title' => 'College Dean Endorsement',
                'description' => 'Endorsement by the college dean.',
                'step_type' => ProposalProcessStep::TYPE_ENDORSEMENT,
                'phase' => 'endorsement',
                'display_order' => 3,
                'is_required' => true,
                'is_gate' => false,
                'condition_config' => ['assigned_role' => 'college_dean', 'assigned_scope' => 'college'],
            ]);

            $step4 = ProposalProcessStep::create([
                'template_version_id' => $version->id,
                'step_key' => 'venue_clearance',
                'title' => 'Venue Clearance',
                'description' => 'Clearance and reservation confirmation from the venue administrator.',
                'step_type' => ProposalProcessStep::TYPE_CLEARANCE,
                'phase' => 'clearance',
                'display_order' => 4,
                'is_required' => true,
                'is_gate' => false,
                'condition_config' => ['assigned_role' => 'venue_admin', 'assigned_scope' => 'venue'],
            ]);

            $step5 = ProposalProcessStep::create([
                'template_version_id' => $version->id,
                'step_key' => 'osa_review_and_approval',
                'title' => 'OSA Review & Approval',
                'description' => 'Final review and permit issuance by the Office of Student Affairs.',
                'step_type' => ProposalProcessStep::TYPE_APPROVAL,
                'phase' => 'approval',
                'display_order' => 5,
                'is_required' => true,
                'is_gate' => true,
                'condition_config' => ['assigned_role' => 'osa', 'assigned_scope' => 'university'],
            ]);

            // Dependencies
            ProposalProcessStepDependency::create([
                'step_id' => $step2->id,
                'prerequisite_step_id' => $step1->id,
            ]);

            ProposalProcessStepDependency::create([
                'step_id' => $step3->id,
                'prerequisite_step_id' => $step2->id,
            ]);

            ProposalProcessStepDependency::create([
                'step_id' => $step4->id,
                'prerequisite_step_id' => $step1->id,
            ]);

            ProposalProcessStepDependency::create([
                'step_id' => $step5->id,
                'prerequisite_step_id' => $step3->id,
            ]);

            ProposalProcessStepDependency::create([
                'step_id' => $step5->id,
                'prerequisite_step_id' => $step4->id,
            ]);

            // Default applicability rule
            ProposalTemplateApplicabilityRule::create([
                'template_version_id' => $version->id,
                'priority' => 0,
                'is_default' => true,
                'status' => ProposalTemplateApplicabilityRule::STATUS_ACTIVE,
            ]);

            // Publish version
            $version->update([
                'status' => ProposalProcessTemplateVersion::STATUS_PUBLISHED,
                'published_at' => now(),
            ]);

            return $version->fresh(['steps.dependencies']);
        });
    }
}
