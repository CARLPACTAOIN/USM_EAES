<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\ProposalProcessStep;
use App\Models\ProposalProcessStepDependency;
use App\Models\ProposalProcessTemplate;
use App\Models\ProposalProcessTemplateVersion;
use App\Models\ProposalTemplateApplicabilityRule;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProposalProcessTemplateManagementService
{
    public function create(array $data, User $actor): ProposalProcessTemplateVersion
    {
        return DB::transaction(function () use ($data, $actor): ProposalProcessTemplateVersion {
            $template = ProposalProcessTemplate::create([
                'name' => $data['template_name'],
                'description' => $data['template_description'] ?? null,
                'status' => ProposalProcessTemplate::STATUS_ACTIVE,
                'created_by' => $actor->id,
            ]);

            $version = ProposalProcessTemplateVersion::create([
                'template_id' => $template->id,
                'version_number' => 1,
                'status' => ProposalProcessTemplateVersion::STATUS_DRAFT,
                'summary' => $data['summary'] ?? null,
            ]);

            $this->replaceDraftDefinition($version, $data);
            $this->audit($actor, 'create-proposal-process-template', $version);

            return $version->fresh($this->relationships());
        });
    }

    public function updateDraft(ProposalProcessTemplateVersion $version, array $data, User $actor): ProposalProcessTemplateVersion
    {
        return DB::transaction(function () use ($version, $data, $actor): ProposalProcessTemplateVersion {
            $version = ProposalProcessTemplateVersion::query()
                ->whereKey($version->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertDraft($version);

            $version->template()->update([
                'name' => $data['template_name'],
                'description' => $data['template_description'] ?? null,
            ]);

            $version->update([
                'summary' => $data['summary'] ?? null,
            ]);

            $this->replaceDraftDefinition($version, $data);
            $this->audit($actor, 'update-proposal-process-template-draft', $version);

            return $version->fresh($this->relationships());
        });
    }

    public function publish(ProposalProcessTemplateVersion $version, User $actor): ProposalProcessTemplateVersion
    {
        return DB::transaction(function () use ($version, $actor): ProposalProcessTemplateVersion {
            $version = ProposalProcessTemplateVersion::query()
                ->whereKey($version->id)
                ->with(['steps', 'applicabilityRules'])
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertDraft($version);

            if ($version->steps->isEmpty()) {
                throw ValidationException::withMessages([
                    'version' => 'Add at least one process step before publishing.',
                ]);
            }

            $version->update([
                'status' => ProposalProcessTemplateVersion::STATUS_PUBLISHED,
                'published_at' => now(),
                'published_by' => $actor->id,
            ]);

            $this->audit($actor, 'publish-proposal-process-template-version', $version);

            return $version->fresh($this->relationships());
        });
    }

    public function retire(ProposalProcessTemplateVersion $version, User $actor): ProposalProcessTemplateVersion
    {
        return DB::transaction(function () use ($version, $actor): ProposalProcessTemplateVersion {
            $version = ProposalProcessTemplateVersion::query()
                ->whereKey($version->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($version->status !== ProposalProcessTemplateVersion::STATUS_PUBLISHED) {
                throw ValidationException::withMessages([
                    'version' => 'Only published template versions can be retired.',
                ]);
            }

            $version->update([
                'status' => ProposalProcessTemplateVersion::STATUS_RETIRED,
                'retired_at' => now(),
                'retired_by' => $actor->id,
            ]);

            $this->audit($actor, 'retire-proposal-process-template-version', $version);

            return $version->fresh($this->relationships());
        });
    }

    public function createDraftFrom(ProposalProcessTemplateVersion $source, User $actor): ProposalProcessTemplateVersion
    {
        return DB::transaction(function () use ($source, $actor): ProposalProcessTemplateVersion {
            $source = ProposalProcessTemplateVersion::query()
                ->whereKey($source->id)
                ->with(['template', 'steps.prerequisiteSteps', 'applicabilityRules'])
                ->lockForUpdate()
                ->firstOrFail();

            $nextVersionNumber = ((int) $source->template->versions()->max('version_number')) + 1;

            $draft = ProposalProcessTemplateVersion::create([
                'template_id' => $source->template_id,
                'version_number' => $nextVersionNumber,
                'status' => ProposalProcessTemplateVersion::STATUS_DRAFT,
                'summary' => 'Draft revision from version '.$source->version_number,
            ]);

            $stepMap = [];
            foreach ($source->steps as $step) {
                $copy = ProposalProcessStep::create([
                    'template_version_id' => $draft->id,
                    'step_key' => $step->step_key,
                    'title' => $step->title,
                    'description' => $step->description,
                    'step_type' => $step->step_type,
                    'phase' => $step->phase,
                    'display_order' => $step->display_order,
                    'is_required' => $step->is_required,
                    'is_gate' => $step->is_gate,
                    'condition_config' => $step->condition_config,
                ]);

                $stepMap[$step->id] = $copy->id;
            }

            foreach ($source->steps as $step) {
                foreach ($step->prerequisiteSteps as $prerequisite) {
                    ProposalProcessStepDependency::create([
                        'step_id' => $stepMap[$step->id],
                        'prerequisite_step_id' => $stepMap[$prerequisite->id],
                    ]);
                }
            }

            foreach ($source->applicabilityRules as $rule) {
                ProposalTemplateApplicabilityRule::create([
                    'template_version_id' => $draft->id,
                    'priority' => $rule->priority,
                    'organizer_role' => $rule->organizer_role,
                    'organization_type' => $rule->organization_type,
                    'college_id' => $rule->college_id,
                    'event_domain' => $rule->event_domain,
                    'activity_category' => $rule->activity_category,
                    'location_type' => $rule->location_type,
                    'is_default' => $rule->is_default,
                    'status' => $rule->status,
                    'effective_start' => $rule->effective_start,
                    'effective_end' => $rule->effective_end,
                ]);
            }

            $this->audit($actor, 'create-proposal-process-template-draft-version', $draft, [
                'source_version_id' => $source->id,
            ]);

            return $draft->fresh($this->relationships());
        });
    }

    private function replaceDraftDefinition(ProposalProcessTemplateVersion $version, array $data): void
    {
        $this->assertDraft($version);

        $version->steps()->each(function (ProposalProcessStep $step): void {
            $step->dependencies()->delete();
        });
        $version->steps()->delete();
        $version->applicabilityRules()->delete();

        $stepByKey = [];
        foreach ($data['steps'] as $stepData) {
            $config = collect([
                'assigned_role' => $stepData['assigned_role'] ?? null,
                'assigned_scope' => $stepData['assigned_scope'] ?? null,
            ])->filter(fn (mixed $value): bool => filled($value))->all();

            $step = ProposalProcessStep::create([
                'template_version_id' => $version->id,
                'step_key' => $stepData['step_key'],
                'title' => $stepData['title'],
                'description' => $stepData['description'] ?? null,
                'step_type' => $stepData['step_type'],
                'phase' => $stepData['phase'] ?? null,
                'display_order' => (int) $stepData['display_order'],
                'is_required' => (bool) ($stepData['is_required'] ?? false),
                'is_gate' => (bool) ($stepData['is_gate'] ?? false),
                'condition_config' => $config === [] ? null : $config,
            ]);

            $stepByKey[$step->step_key] = $step;
        }

        foreach ($data['steps'] as $stepData) {
            $step = $stepByKey[$stepData['step_key']];

            foreach (($stepData['prerequisites'] ?? []) as $prerequisiteKey) {
                ProposalProcessStepDependency::create([
                    'step_id' => $step->id,
                    'prerequisite_step_id' => $stepByKey[$prerequisiteKey]->id,
                ]);
            }
        }

        foreach (($data['rules'] ?? []) as $ruleData) {
            ProposalTemplateApplicabilityRule::create([
                'template_version_id' => $version->id,
                'priority' => (int) $ruleData['priority'],
                'organizer_role' => $ruleData['organizer_role'] ?? null,
                'organization_type' => $ruleData['organization_type'] ?? null,
                'college_id' => $ruleData['college_id'] ?? null,
                'event_domain' => $ruleData['event_domain'] ?? null,
                'activity_category' => $ruleData['activity_category'] ?? null,
                'location_type' => $ruleData['location_type'] ?? null,
                'is_default' => (bool) ($ruleData['is_default'] ?? false),
                'status' => $ruleData['status'],
                'effective_start' => $ruleData['effective_start'] ?? null,
                'effective_end' => $ruleData['effective_end'] ?? null,
            ]);
        }
    }

    private function assertDraft(ProposalProcessTemplateVersion $version): void
    {
        if ($version->status !== ProposalProcessTemplateVersion::STATUS_DRAFT) {
            throw ValidationException::withMessages([
                'version' => 'Create a new draft version before editing this published or retired template version.',
            ]);
        }
    }

    private function audit(
        User $actor,
        string $action,
        ProposalProcessTemplateVersion $version,
        array $extra = []
    ): void {
        AuditLog::create([
            'target_id' => $version->id,
            'admin_id' => $actor->id,
            'action' => $action,
            'details' => array_merge([
                'template_id' => $version->template_id,
                'version_number' => $version->version_number,
                'status' => $version->status,
            ], $extra),
        ]);
    }

    private function relationships(): array
    {
        return [
            'template',
            'steps.prerequisiteSteps',
            'applicabilityRules',
        ];
    }
}
