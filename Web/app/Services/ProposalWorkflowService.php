<?php

namespace App\Services;

use App\Exceptions\WorkflowAssignmentResolutionException;
use App\Models\Event;
use App\Models\EventParticipationScope;
use App\Models\ProposalProcessStep;
use App\Models\ProposalProcessStepDependency;
use App\Models\ProposalProcessTemplate;
use App\Models\ProposalProcessTemplateVersion;
use App\Models\ProposalTemplateApplicabilityRule;
use App\Models\ProposalWorkflowRun;
use App\Models\ProposalWorkflowTask;
use App\Models\User;
use App\Support\ProposalRevisionTargets;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProposalWorkflowService
{
    public function __construct(
        private readonly ProposalWorkflowAssignmentResolver $assignmentResolver,
        private readonly EventNotificationService $notifications,
        private readonly ProposalRevisionTargets $revisionTargets,
    ) {}

    /**
     * Bind an event proposal to an immutable published template workflow run on first submission.
     * Idempotent: returns existing run if already bound.
     */
    public function bindFirstSubmission(Event $event, User $actor): ProposalWorkflowRun
    {
        $existing = ProposalWorkflowRun::where('event_id', $event->id)
            ->whereNull('event_participation_scope_id')
            ->first();
        if ($existing) {
            return $existing;
        }

        try {
            return DB::transaction(function () use ($event, $actor): ProposalWorkflowRun {
                $existing = ProposalWorkflowRun::where('event_id', $event->id)
                    ->whereNull('event_participation_scope_id')
                    ->lockForUpdate()
                    ->first();
                if ($existing) {
                    return $existing;
                }

                $templateVersion = $this->resolveApplicableTemplateVersion($event);

                return $this->createRunFromTemplate($event, $actor, $templateVersion);
            });
        } catch (WorkflowAssignmentResolutionException $exception) {
            $this->alertOsaAboutAssignmentFailure($event, $exception);

            throw $exception;
        }
    }

    /**
     * Bind a participant scope packet to an immutable published participant template workflow run.
     * Idempotent: returns existing run if the scope is already bound.
     */
    public function bindParticipantSubmission(EventParticipationScope $scope, User $actor): ProposalWorkflowRun
    {
        $scope->loadMissing(['event.organization', 'organization']);

        $existing = ProposalWorkflowRun::where('event_participation_scope_id', $scope->id)->first();
        if ($existing) {
            return $existing;
        }

        $event = $scope->event;

        try {
            return DB::transaction(function () use ($scope, $event, $actor): ProposalWorkflowRun {
                $existing = ProposalWorkflowRun::where('event_participation_scope_id', $scope->id)
                    ->lockForUpdate()
                    ->first();
                if ($existing) {
                    return $existing;
                }

                $templateVersion = $this->resolveApplicableParticipantTemplateVersion($scope);

                return $this->createRunFromTemplate($event, $actor, $templateVersion, $scope);
            });
        } catch (WorkflowAssignmentResolutionException $exception) {
            $this->alertOsaAboutAssignmentFailure($event, $exception);

            throw $exception;
        }
    }

    /**
     * @return Collection<int, ProposalWorkflowTask>
     */
    public function reopenReturnedTasksForResubmission(Event $event, bool $returnedTasksAddressed): Collection
    {
        $run = ProposalWorkflowRun::query()
            ->where('event_id', $event->id)
            ->whereNull('event_participation_scope_id')
            ->lockForUpdate()
            ->first();

        if (! $run) {
            return collect();
        }

        $returnedTasks = ProposalWorkflowTask::query()
            ->with(['assignedUser', 'workflowRun.event.organization'])
            ->where('workflow_run_id', $run->id)
            ->where('status', ProposalWorkflowTask::STATUS_REVISION_REQUESTED)
            ->lockForUpdate()
            ->orderBy('display_order')
            ->get();

        if ($returnedTasks->isEmpty()) {
            throw new DomainException('Returned workflow tasks must be addressed before resubmission.');
        }

        if (! $returnedTasksAddressed) {
            throw new DomainException('Confirm that returned workflow tasks and packet corrections were addressed before resubmitting.');
        }

        $event->loadMissing('canonicalPpaPacket');
        $latestReturnedAt = $returnedTasks
            ->map(fn (ProposalWorkflowTask $task) => $task->decided_at ?? $task->completed_at ?? $task->updated_at)
            ->filter()
            ->sortDesc()
            ->first();
        $latestPacketCorrectionAt = collect([$event->updated_at, $event->canonicalPpaPacket?->updated_at])
            ->filter()
            ->sortDesc()
            ->first();

        if (! $latestReturnedAt || ! $latestPacketCorrectionAt || $latestPacketCorrectionAt->lessThanOrEqualTo($latestReturnedAt)) {
            throw new DomainException('Edit the returned proposal packet before resubmitting the workflow task.');
        }

        $this->assertTargetedRevisionItemsAddressed($returnedTasks);

        $availableAt = now();
        $run->update([
            'status' => ProposalWorkflowRun::STATUS_IN_PROGRESS,
            'submitted_at' => $availableAt,
            'completed_at' => null,
        ]);

        $allTasks = ProposalWorkflowTask::query()
            ->with('processStep.dependencies')
            ->where('workflow_run_id', $run->id)
            ->lockForUpdate()
            ->get()
            ->keyBy('process_step_id');

        $returnedStepIds = $returnedTasks->pluck('process_step_id')->values();

        $returnedTasks->each(function (ProposalWorkflowTask $task) use ($allTasks, $availableAt, $returnedStepIds): void {
            $canOpen = $this->returnedTaskCanOpen($task, $allTasks, $returnedStepIds);
            $metadata = $task->metadata ?? [];

            if (data_get($metadata, 'revision.target')) {
                data_set($metadata, 'revision.addressed_at', $availableAt->toIso8601String());
            }

            $task->update([
                'status' => $canOpen
                    ? ProposalWorkflowTask::STATUS_AVAILABLE
                    : ProposalWorkflowTask::STATUS_BLOCKED,
                'decision' => null,
                'remarks' => null,
                'decided_by' => null,
                'decided_at' => null,
                'completed_at' => null,
                'available_at' => $canOpen ? $availableAt : null,
                'metadata' => $metadata,
            ]);
        });

        return $returnedTasks->map->fresh(['assignedUser', 'workflowRun.event.organization']);
    }

    /**
     * Return venue-dependent workflow tasks to the organizer during a venue amendment.
     *
     * Prior Approval Trails are intentionally preserved. The live task rows are reopened on the
     * same Workflow Run after the organizer submits replacement venue evidence.
     *
     * @return Collection<int, ProposalWorkflowTask>
     */
    public function returnVenueDependentTasksForAmendment(Event $event, User $actor, string $reason): Collection
    {
        $run = ProposalWorkflowRun::query()
            ->where('event_id', $event->id)
            ->whereNull('event_participation_scope_id')
            ->lockForUpdate()
            ->first();

        if (! $run) {
            return collect();
        }

        $tasks = ProposalWorkflowTask::query()
            ->with('processStep')
            ->where('workflow_run_id', $run->id)
            ->lockForUpdate()
            ->orderBy('display_order')
            ->get();

        $returnedAt = now();
        $returnedTasks = $tasks->filter(fn (ProposalWorkflowTask $task): bool => $this->isVenueDependentTask($task));

        if ($returnedTasks->isEmpty()) {
            return collect();
        }

        $runMetadata = $run->metadata ?? [];
        data_set($runMetadata, 'venue_amendment', [
            'reason' => trim($reason),
            'returned_by' => [
                'id' => $actor->id,
                'name' => $actor->name,
            ],
            'returned_at' => $returnedAt->toIso8601String(),
            'permit_version' => $event->permit_version,
        ]);

        $run->update([
            'status' => ProposalWorkflowRun::STATUS_REVISION_REQUESTED,
            'completed_at' => null,
            'metadata' => $runMetadata,
        ]);

        $returnedTasks->each(function (ProposalWorkflowTask $task) use ($actor, $event, $reason, $returnedAt): void {
            $metadata = $task->metadata ?? [];
            data_set($metadata, 'venue_amendment_return', [
                'reason' => trim($reason),
                'returned_by' => [
                    'id' => $actor->id,
                    'name' => $actor->name,
                ],
                'returned_at' => $returnedAt->toIso8601String(),
                'permit_version' => $event->permit_version,
                'previous_status' => $task->status,
                'previous_decision' => $task->decision,
            ]);

            $task->update([
                'status' => ProposalWorkflowTask::STATUS_REVISION_REQUESTED,
                'decision' => null,
                'remarks' => 'Venue amendment: '.trim($reason),
                'decided_by' => null,
                'decided_at' => $returnedAt,
                'completed_at' => $returnedAt,
                'available_at' => null,
                'metadata' => $metadata,
            ]);
        });

        return $returnedTasks->map->fresh(['assignedUser', 'workflowRun.event.organization']);
    }

    /**
     * Resolve the highest priority applicable published template version for the event.
     */
    public function resolveApplicableTemplateVersion(Event $event): ProposalProcessTemplateVersion
    {
        return $this->resolveApplicableTemplateVersionFor($event);
    }

    public function resolveApplicableParticipantTemplateVersion(EventParticipationScope $scope): ProposalProcessTemplateVersion
    {
        $scope->loadMissing(['event.organization', 'organization']);

        return $this->resolveApplicableTemplateVersionFor($scope->event, $scope);
    }

    /**
     * Resolve the highest priority applicable published template version for an event or participant scope.
     */
    private function resolveApplicableTemplateVersionFor(Event $event, ?EventParticipationScope $scope = null): ProposalProcessTemplateVersion
    {
        $event->loadMissing(['organization']);
        $scope?->loadMissing(['organization']);

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

        $targetRole = $scope ? EventParticipationScope::ROLE_PARTICIPANT : EventParticipationScope::ROLE_ORGANIZER;
        $subjectOrganization = $scope?->organization ?: $event->organization;
        $subjectCollegeId = $scope?->college_id ?: $subjectOrganization?->college_id;
        $subjectOrgType = $subjectOrganization?->type ?? $subjectOrganization?->organization_type;

        foreach ($rules as $rule) {
            if ($rule->is_default) {
                continue;
            }

            if (! $this->ruleMatchesOrganizerRole($rule, $targetRole)) {
                continue;
            }

            if ($rule->college_id !== null && $rule->college_id !== $subjectCollegeId) {
                continue;
            }

            if ($rule->organization_type !== null && $rule->organization_type !== $subjectOrgType) {
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

        $defaultRule = $rules
            ->filter(fn (ProposalTemplateApplicabilityRule $rule): bool => $this->ruleMatchesOrganizerRole($rule, $targetRole, allowImplicitOrganizerDefault: true))
            ->firstWhere('is_default', true);
        if ($defaultRule) {
            return $defaultRule->templateVersion;
        }

        return $scope
            ? $this->ensureDefaultPublishedParticipantTemplate()
            : $this->ensureDefaultPublishedTemplate();
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
                'step_key' => 'security_clearance',
                'title' => 'Security Clearance',
                'description' => 'Clearance from the Office of Security for campus safety routing.',
                'step_type' => ProposalProcessStep::TYPE_CLEARANCE,
                'phase' => 'clearance',
                'display_order' => 4,
                'is_required' => true,
                'is_gate' => false,
                'condition_config' => ['assigned_role' => 'chief_security', 'assigned_scope' => 'university'],
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

    public function ensureDefaultPublishedParticipantTemplate(): ProposalProcessTemplateVersion
    {
        return DB::transaction(function (): ProposalProcessTemplateVersion {
            $template = ProposalProcessTemplate::firstOrCreate(
                ['name' => 'Standard Participant PPA Workflow'],
                [
                    'description' => 'Canonical approval workflow for participant PPA packets under a parent event permit.',
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
                'summary' => 'Initial participant PPA approval workflow template version.',
            ]);

            ProposalProcessStep::create([
                'template_version_id' => $version->id,
                'step_key' => 'participant_osa_approval',
                'title' => 'Participant OSA Approval',
                'description' => 'Final participant PPA review by the Office of Student Affairs.',
                'step_type' => ProposalProcessStep::TYPE_APPROVAL,
                'phase' => 'participant_ppa',
                'display_order' => 1,
                'is_required' => true,
                'is_gate' => true,
                'condition_config' => [
                    'assigned_role' => 'osa',
                    'assigned_scope' => 'university',
                    'allow_unassigned_required' => true,
                ],
            ]);

            ProposalTemplateApplicabilityRule::create([
                'template_version_id' => $version->id,
                'priority' => 0,
                'organizer_role' => EventParticipationScope::ROLE_PARTICIPANT,
                'is_default' => true,
                'status' => ProposalTemplateApplicabilityRule::STATUS_ACTIVE,
            ]);

            $version->update([
                'status' => ProposalProcessTemplateVersion::STATUS_PUBLISHED,
                'published_at' => now(),
            ]);

            return $version->fresh(['steps.dependencies']);
        });
    }

    private function createRunFromTemplate(
        Event $event,
        User $actor,
        ProposalProcessTemplateVersion $templateVersion,
        ?EventParticipationScope $scope = null,
    ): ProposalWorkflowRun {
        $steps = $templateVersion->steps()->with('dependencies')->orderBy('display_order')->get();
        $submittedAt = now();

        $run = ProposalWorkflowRun::create([
            'event_id' => $event->id,
            'event_participation_scope_id' => $scope?->id,
            'template_version_id' => $templateVersion->id,
            'status' => ProposalWorkflowRun::STATUS_IN_PROGRESS,
            'started_by' => $actor->id,
            'started_at' => $submittedAt,
            'submitted_at' => $submittedAt,
            'metadata' => [
                'proposal_subject' => $scope ? EventParticipationScope::ROLE_PARTICIPANT : EventParticipationScope::ROLE_ORGANIZER,
                'event_participation_scope_id' => $scope?->id,
            ],
        ]);

        foreach ($steps as $step) {
            $hasPrerequisites = $step->dependencies->isNotEmpty();

            $status = $hasPrerequisites
                ? ProposalWorkflowTask::STATUS_BLOCKED
                : ProposalWorkflowTask::STATUS_AVAILABLE;

            $assignment = $this->assignmentResolver->resolve($event, $step, $actor, $scope);

            ProposalWorkflowTask::create([
                'workflow_run_id' => $run->id,
                'process_step_id' => $step->id,
                'status' => $status,
                'display_order' => $step->display_order,
                'step_key' => $step->step_key,
                'step_type' => $step->step_type,
                'title' => $step->title,
                'description' => $step->description,
                'assigned_user_id' => $assignment['assigned_user_id'],
                'assigned_role' => $assignment['assigned_role'],
                'assigned_scope' => $assignment['assigned_scope'],
                'available_at' => $hasPrerequisites ? null : $submittedAt,
                'metadata' => $assignment['metadata'] + [
                    'proposal_subject' => $scope ? EventParticipationScope::ROLE_PARTICIPANT : EventParticipationScope::ROLE_ORGANIZER,
                    'event_participation_scope_id' => $scope?->id,
                ],
            ]);
        }

        $freshRun = $run->fresh(['tasks']);
        foreach ($freshRun->tasks as $createdTask) {
            if ($createdTask->assigned_user_id) {
                $this->notifications->notifyWorkflowTaskAssigned($createdTask);
            }
        }

        return $freshRun;
    }

    private function ruleMatchesOrganizerRole(
        ProposalTemplateApplicabilityRule $rule,
        string $targetRole,
        bool $allowImplicitOrganizerDefault = false,
    ): bool {
        if ($targetRole === EventParticipationScope::ROLE_PARTICIPANT) {
            return $rule->organizer_role === EventParticipationScope::ROLE_PARTICIPANT;
        }

        if ($rule->organizer_role === EventParticipationScope::ROLE_PARTICIPANT) {
            return false;
        }

        return $rule->organizer_role === null
            || $rule->organizer_role === EventParticipationScope::ROLE_ORGANIZER
            || $allowImplicitOrganizerDefault;
    }

    /**
     * @param  Collection<string, ProposalWorkflowTask>  $allTasksByStep
     * @param  Collection<int, string>  $returnedStepIds
     */
    private function returnedTaskCanOpen(ProposalWorkflowTask $task, Collection $allTasksByStep, Collection $returnedStepIds): bool
    {
        $task->loadMissing('processStep.dependencies');

        return $task->processStep->dependencies->every(function (ProposalProcessStepDependency $dependency) use ($allTasksByStep, $returnedStepIds): bool {
            if ($returnedStepIds->contains($dependency->prerequisite_step_id)) {
                return false;
            }

            $prerequisiteTask = $allTasksByStep->get($dependency->prerequisite_step_id);

            return $prerequisiteTask !== null
                && in_array($prerequisiteTask->status, [
                    ProposalWorkflowTask::STATUS_APPROVED,
                    ProposalWorkflowTask::STATUS_NOT_REQUIRED,
                ], true);
        });
    }

    /**
     * @param  Collection<int, ProposalWorkflowTask>  $returnedTasks
     */
    private function assertTargetedRevisionItemsAddressed(Collection $returnedTasks): void
    {
        foreach ($returnedTasks as $task) {
            if (! data_get($task->metadata, 'revision.target')) {
                continue;
            }

            $returnedAt = $task->decided_at ?? $task->completed_at ?? $task->updated_at;
            $target = data_get($task->metadata, 'revision.target');
            $targetType = is_array($target) ? ($target['type'] ?? null) : null;
            $addressedAt = $this->revisionTargets->targetAddressedAt($task);
            $previousFingerprint = data_get($task->metadata, 'revision.target_fingerprint');
            $currentFingerprint = is_array($target)
                ? $this->revisionTargets->targetFingerprintForTask($task, $target)
                : null;
            $sectionChanged = $previousFingerprint !== null
                && $currentFingerprint !== null
                && $previousFingerprint !== $currentFingerprint;
            $attachmentUpdated = $addressedAt !== null && $returnedAt !== null && $addressedAt->greaterThan($returnedAt);
            $targetAddressed = match ($targetType) {
                ProposalRevisionTargets::TYPE_PACKET_SECTION => $sectionChanged,
                ProposalRevisionTargets::TYPE_ATTACHMENT_REQUIREMENT => $attachmentUpdated,
                default => false,
            };

            if (! $targetAddressed) {
                $targetLabel = $this->revisionTargets->labelForMetadata($target) ?: 'targeted packet content';

                throw new DomainException("Update the targeted revision item ({$targetLabel}) before resubmitting.");
            }
        }
    }

    private function isVenueDependentTask(ProposalWorkflowTask $task): bool
    {
        $task->loadMissing('processStep');

        $role = (string) $task->assigned_role;
        $stepKey = Str::lower((string) $task->step_key);
        $phase = Str::lower((string) $task->processStep?->phase);

        return in_array($role, ['venue_admin', 'chief_security', 'osa'], true)
            || Str::contains($stepKey, ['venue', 'security', 'permit'])
            || in_array($phase, ['venue', 'venue_use', 'permit', 'clearance'], true);
    }

    private function alertOsaAboutAssignmentFailure(Event $event, WorkflowAssignmentResolutionException $exception): void
    {
        $key = "proposal_workflow_assignment_failed:{$event->id}:{$exception->stepKey}:{$exception->roleKey}";

        User::role('Super Admin (OSA)')->get()->each(function (User $recipient) use ($event, $exception, $key): void {
            $this->notifications->notifyUser($recipient, $key, [
                'kind' => 'proposal_workflow_assignment_failed',
                'event_id' => $event->id,
                'event_title' => $event->title,
                'step_key' => $exception->stepKey,
                'step_title' => $exception->stepTitle,
                'role_key' => $exception->roleKey,
                'role_label' => $exception->roleLabel,
                'title' => 'Workflow assignment failed',
                'body' => "{$exception->roleLabel} could not be resolved for {$event->title}.",
                'action_label' => 'Open Event Proposals',
                'action_url' => route('dashboard.events'),
                'severity' => 'warning',
                'needs_action' => true,
            ]);
        });
    }
}
