<?php

namespace Tests\Feature;

use App\Livewire\Dashboard\EventProposalWorkflow;
use App\Models\AdminAssignment;
use App\Models\ApprovalTrail;
use App\Models\CanonicalPpaAttachment;
use App\Models\College;
use App\Models\Event;
use App\Models\EventParticipationScope;
use App\Models\Organization;
use App\Models\ProposalProcessStep;
use App\Models\ProposalProcessStepDependency;
use App\Models\ProposalProcessTemplate;
use App\Models\ProposalProcessTemplateVersion;
use App\Models\ProposalWorkflowRun;
use App\Models\ProposalWorkflowTask;
use App\Models\SchoolYear;
use App\Models\University;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Tests\TestCase;

class ProposalWorkflowTaskDecisionTest extends TestCase
{
    use RefreshDatabase;

    private ProposalProcessTemplateVersion $version;

    private SchoolYear $schoolYear;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->schoolYear = SchoolYear::create([
            'label' => '2026-2027',
            'starts_on' => today()->subMonth(),
            'ends_on' => today()->addMonths(10),
            'status' => SchoolYear::STATUS_ACTIVE,
            'activated_at' => now(),
        ]);

        $university = University::create([
            'name' => 'University of Southern Mindanao',
            'domain' => 'usm.edu.ph',
        ]);

        $college = College::create([
            'university_id' => $university->id,
            'name' => 'College of Workflow Decisions',
            'code' => 'CWD',
        ]);

        $this->organization = Organization::create([
            'college_id' => $college->id,
            'name' => 'Decision Test Society',
            'acronym' => 'DTS',
            'type' => 'society',
            'status' => 'active',
        ]);

        $template = ProposalProcessTemplate::create([
            'name' => 'Workflow Decision Test Template',
            'status' => ProposalProcessTemplate::STATUS_ACTIVE,
        ]);

        $this->version = ProposalProcessTemplateVersion::create([
            'template_id' => $template->id,
            'version_number' => 1,
            'status' => ProposalProcessTemplateVersion::STATUS_DRAFT,
        ]);
    }

    public function test_assigned_approver_can_approve_available_task_and_unblock_downstream_tasks(): void
    {
        $actor = $this->osaUser('osa.decision');
        $firstStep = $this->step('osa_review', 'OSA Review', 1);
        $secondStep = $this->step('security_clearance', 'Security Clearance', 2);
        $this->dependency($secondStep, $firstStep);
        $this->publishVersion();

        [$event, $run] = $this->workflowRun($actor);
        $task = $this->task($run, $firstStep, $actor, ProposalWorkflowTask::STATUS_AVAILABLE);
        $blockedTask = $this->task($run, $secondStep, $actor, ProposalWorkflowTask::STATUS_BLOCKED);

        $response = $this->actingAs($actor)
            ->post(route('dashboard.workflow-tasks.decide', $task), [
                'decision' => ProposalWorkflowTask::DECISION_APPROVED,
            ]);

        $response->assertRedirect(route('dashboard.workflow-tasks.show', $task));

        $task->refresh();
        $blockedTask->refresh();

        $this->assertSame(ProposalWorkflowTask::STATUS_APPROVED, $task->status);
        $this->assertSame(ProposalWorkflowTask::DECISION_APPROVED, $task->decision);
        $this->assertSame($actor->id, $task->decided_by);
        $this->assertNotNull($task->completed_at);
        $this->assertSame(ProposalWorkflowTask::STATUS_AVAILABLE, $blockedTask->status);
        $this->assertNotNull($blockedTask->available_at);

        $this->assertDatabaseHas('approval_trails', [
            'proposal_id' => $event->id,
            'workflow_run_id' => $run->id,
            'workflow_task_id' => $task->id,
            'actor_id' => $actor->id,
            'decision' => ProposalWorkflowTask::DECISION_APPROVED,
            'previous_status' => ProposalWorkflowTask::STATUS_AVAILABLE,
            'resulting_status' => ProposalWorkflowTask::STATUS_APPROVED,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'target_id' => $task->id,
            'admin_id' => $actor->id,
            'action' => 'workflow-task-decision',
        ]);

        $this->actingAs($actor)
            ->get(route('dashboard.workflow-tasks.show', $task))
            ->assertOk()
            ->assertSee('Approval Trail')
            ->assertSee('Approved');
    }

    public function test_global_event_decision_shortcuts_are_unavailable(): void
    {
        $actor = $this->osaUser('osa.no-shortcuts');
        [$event] = $this->workflowRun($actor);

        $this->assertFalse(Route::has('dashboard.events.review'));
        $this->assertFalse(Route::has('dashboard.events.approve'));
        $this->assertFalse(Route::has('dashboard.events.revision'));
        $this->assertFalse(Route::has('dashboard.events.reject'));

        foreach (['review', 'approve', 'revision', 'reject'] as $action) {
            $this->actingAs($actor)
                ->post("/dashboard/events/{$event->id}/{$action}", [
                    'review_remarks' => 'This shortcut must not be reachable.',
                ])
                ->assertNotFound();

            $this->actingAs($actor)
                ->postJson("/api/v1/events/{$event->id}/{$action}", [
                    'review_remarks' => 'This shortcut must not be reachable.',
                ])
                ->assertNotFound();
        }

        Livewire::actingAs($actor)
            ->test(EventProposalWorkflow::class, ['eventId' => $event->id])
            ->assertDontSeeHtml('wire:click="startReview"')
            ->assertDontSeeHtml('wire:click="approveProposal"')
            ->assertDontSeeHtml('wire:submit="requestRevision"')
            ->assertDontSeeHtml('wire:click="rejectProposal"');

        $this->assertSame(Event::STATUS_UNDER_REVIEW, $event->fresh()->status);
        $this->assertDatabaseCount('approval_trails', 0);
    }

    public function test_unassigned_out_of_order_completed_cross_tenant_and_wrong_scope_decisions_do_not_change_state(): void
    {
        $assignedApprover = $this->osaUser('osa.assigned');
        $otherApprover = $this->osaUser('osa.unassigned');
        $step = $this->step('osa_review', 'OSA Review', 1);
        $this->publishVersion();

        [$unassignedEvent, $unassignedRun] = $this->workflowRun($assignedApprover);
        $unassignedTask = $this->task($unassignedRun, $step, $assignedApprover, ProposalWorkflowTask::STATUS_AVAILABLE);

        $this->actingAs($otherApprover)
            ->post(route('dashboard.workflow-tasks.decide', $unassignedTask), [
                'decision' => ProposalWorkflowTask::DECISION_APPROVED,
            ])
            ->assertForbidden();

        [$blockedEvent, $blockedRun] = $this->workflowRun($assignedApprover);
        $blockedTask = $this->task($blockedRun, $step, $assignedApprover, ProposalWorkflowTask::STATUS_BLOCKED);

        $this->actingAs($assignedApprover)
            ->post(route('dashboard.workflow-tasks.decide', $blockedTask), [
                'decision' => ProposalWorkflowTask::DECISION_APPROVED,
            ])
            ->assertSessionHasErrors('task');

        [$completedEvent, $completedRun] = $this->workflowRun($assignedApprover);
        $completedTask = $this->task($completedRun, $step, $assignedApprover, ProposalWorkflowTask::STATUS_APPROVED);

        $this->actingAs($assignedApprover)
            ->post(route('dashboard.workflow-tasks.decide', $completedTask), [
                'decision' => ProposalWorkflowTask::DECISION_APPROVED,
            ])
            ->assertForbidden();

        [$wrongScopeEvent, $wrongScopeRun] = $this->workflowRun($assignedApprover);
        $wrongScopeTask = $this->task($wrongScopeRun, $step, $assignedApprover, ProposalWorkflowTask::STATUS_AVAILABLE);
        $wrongScopeTask->update([
            'assigned_role' => 'dept_chair',
            'assigned_scope' => 'department',
        ]);

        $this->actingAs($assignedApprover)
            ->post(route('dashboard.workflow-tasks.decide', $wrongScopeTask), [
                'decision' => ProposalWorkflowTask::DECISION_APPROVED,
            ])
            ->assertForbidden();

        $otherOrganization = Organization::create([
            'college_id' => $this->organization->college_id,
            'name' => 'Other Tenant Society',
            'acronym' => 'OTS',
            'type' => 'society',
            'status' => 'active',
        ]);
        $crossTenantApprover = User::factory()->create([
            'email' => 'venue.cross-tenant@usm.edu.ph',
        ]);
        $crossTenantApprover->assignRole('Office Event Admin');

        foreach (['Office Event Admin', 'Venue Custodian'] as $roleName) {
            AdminAssignment::create([
                'user_id' => $crossTenantApprover->id,
                'role_name' => $roleName,
                'organization_id' => $otherOrganization->id,
                'college_id' => $otherOrganization->college_id,
                'school_year_id' => $this->schoolYear->id,
                'academic_year' => $this->schoolYear->label,
                'status' => AdminAssignment::STATUS_ACTIVE,
                'is_primary_admin' => false,
                'approved_at' => now(),
            ]);
        }

        [$crossTenantEvent, $crossTenantRun] = $this->workflowRun($crossTenantApprover);
        $crossTenantScope = EventParticipationScope::create([
            'event_id' => $crossTenantEvent->id,
            'school_year_id' => $this->schoolYear->id,
            'participation_role' => EventParticipationScope::ROLE_ORGANIZER,
            'participant_type' => 'organization',
            'organization_id' => $this->organization->id,
            'participant_name_snapshot' => $this->organization->name,
            'ppa_required' => true,
            'ppa_status' => EventParticipationScope::PPA_UNDER_REVIEW,
            'status' => EventParticipationScope::STATUS_ACTIVE,
        ]);
        $crossTenantTask = $this->task($crossTenantRun, $step, $crossTenantApprover, ProposalWorkflowTask::STATUS_AVAILABLE);
        $crossTenantMetadata = $crossTenantTask->metadata;
        data_set($crossTenantMetadata, 'assignment.role_key', 'venue_admin');
        $crossTenantTask->update([
            'assigned_role' => 'venue_admin',
            'assigned_scope' => 'organization',
            'metadata' => $crossTenantMetadata,
        ]);

        $this->actingAs($crossTenantApprover)
            ->post(route('dashboard.workflow-tasks.decide', $crossTenantTask), [
                'decision' => ProposalWorkflowTask::DECISION_APPROVED,
            ])
            ->assertForbidden();

        foreach ([$unassignedEvent, $blockedEvent, $completedEvent, $wrongScopeEvent, $crossTenantEvent] as $event) {
            $this->assertSame(Event::STATUS_UNDER_REVIEW, $event->fresh()->status);
        }

        foreach ([$unassignedRun, $blockedRun, $completedRun, $wrongScopeRun, $crossTenantRun] as $run) {
            $this->assertSame(ProposalWorkflowRun::STATUS_IN_PROGRESS, $run->fresh()->status);
        }

        $this->assertSame(ProposalWorkflowTask::STATUS_AVAILABLE, $unassignedTask->fresh()->status);
        $this->assertSame(ProposalWorkflowTask::STATUS_BLOCKED, $blockedTask->fresh()->status);
        $this->assertSame(ProposalWorkflowTask::STATUS_APPROVED, $completedTask->fresh()->status);
        $this->assertSame(ProposalWorkflowTask::STATUS_AVAILABLE, $wrongScopeTask->fresh()->status);
        $this->assertSame(ProposalWorkflowTask::STATUS_AVAILABLE, $crossTenantTask->fresh()->status);
        $this->assertSame(EventParticipationScope::PPA_UNDER_REVIEW, $crossTenantScope->fresh()->ppa_status);
        $this->assertDatabaseCount('approval_trails', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_first_accepted_task_decision_activates_review_internally(): void
    {
        $actor = $this->osaUser('osa.internal-review');
        $firstStep = $this->step('osa_intake', 'OSA Intake', 1);
        $secondStep = $this->step('osa_final', 'OSA Final Approval', 2);
        $this->dependency($secondStep, $firstStep);
        $this->publishVersion();

        [$event, $run] = $this->workflowRun($actor);
        $event->update(['status' => Event::STATUS_SUBMITTED]);
        $task = $this->task($run, $firstStep, $actor, ProposalWorkflowTask::STATUS_AVAILABLE);
        $this->task($run, $secondStep, $actor, ProposalWorkflowTask::STATUS_BLOCKED);

        $this->actingAs($actor)
            ->post(route('dashboard.workflow-tasks.decide', $task), [
                'decision' => ProposalWorkflowTask::DECISION_APPROVED,
            ])
            ->assertRedirect(route('dashboard.workflow-tasks.show', $task));

        $this->assertSame(Event::STATUS_UNDER_REVIEW, $event->fresh()->status);
        $this->assertDatabaseHas('approval_trails', [
            'workflow_task_id' => $task->id,
            'decision' => ProposalWorkflowTask::DECISION_APPROVED,
        ]);
    }

    public function test_rejected_and_revision_requested_decisions_require_remarks(): void
    {
        $actor = $this->osaUser('osa.remarks');
        $step = $this->step('osa_review', 'OSA Review', 1);
        $this->publishVersion();
        [, $run] = $this->workflowRun($actor);

        $rejectedTask = $this->task($run, $step, $actor, ProposalWorkflowTask::STATUS_AVAILABLE);

        $this->actingAs($actor)
            ->from(route('dashboard.workflow-tasks.show', $rejectedTask))
            ->post(route('dashboard.workflow-tasks.decide', $rejectedTask), [
                'decision' => ProposalWorkflowTask::DECISION_REJECTED,
            ])
            ->assertRedirect(route('dashboard.workflow-tasks.show', $rejectedTask))
            ->assertSessionHasErrors('remarks');

        $this->assertSame(ProposalWorkflowTask::STATUS_AVAILABLE, $rejectedTask->fresh()->status);
        $this->assertDatabaseCount('approval_trails', 0);

        [, $revisionRun] = $this->workflowRun($actor);
        $revisionTask = $this->task($revisionRun, $step, $actor, ProposalWorkflowTask::STATUS_AVAILABLE, 'osa_revision_review');

        $this->actingAs($actor)
            ->from(route('dashboard.workflow-tasks.show', $revisionTask))
            ->post(route('dashboard.workflow-tasks.decide', $revisionTask), [
                'decision' => ProposalWorkflowTask::DECISION_REVISION_REQUESTED,
            ])
            ->assertRedirect(route('dashboard.workflow-tasks.show', $revisionTask))
            ->assertSessionHasErrors('remarks');

        $this->assertSame(ProposalWorkflowTask::STATUS_AVAILABLE, $revisionTask->fresh()->status);
        $this->assertDatabaseCount('approval_trails', 0);
    }

    public function test_revision_requested_decision_requires_revision_target(): void
    {
        $actor = $this->osaUser('osa.target-required');
        $step = $this->step('osa_review', 'OSA Review', 1);
        $this->publishVersion();
        [, $run] = $this->workflowRun($actor);
        $task = $this->task($run, $step, $actor, ProposalWorkflowTask::STATUS_AVAILABLE);

        $this->actingAs($actor)
            ->from(route('dashboard.workflow-tasks.show', $task))
            ->post(route('dashboard.workflow-tasks.decide', $task), [
                'decision' => ProposalWorkflowTask::DECISION_REVISION_REQUESTED,
                'remarks' => 'Please correct the target section.',
            ])
            ->assertRedirect(route('dashboard.workflow-tasks.show', $task))
            ->assertSessionHasErrors('revision_target_key');

        $this->assertSame(ProposalWorkflowTask::STATUS_AVAILABLE, $task->fresh()->status);
        $this->assertDatabaseCount('approval_trails', 0);
    }

    public function test_revision_requested_decision_stores_target_metadata_in_task_and_approval_trail(): void
    {
        $actor = $this->osaUser('osa.target-stored');
        $step = $this->step('osa_review', 'OSA Review', 1);
        $this->publishVersion();
        [, $run] = $this->workflowRun($actor);
        $task = $this->task($run, $step, $actor, ProposalWorkflowTask::STATUS_AVAILABLE);

        $this->actingAs($actor)
            ->post(route('dashboard.workflow-tasks.decide', $task), [
                'decision' => ProposalWorkflowTask::DECISION_REVISION_REQUESTED,
                'remarks' => 'Attach the corrected venue confirmation evidence.',
                'revision_target' => 'attachment_requirement:'.CanonicalPpaAttachment::TYPE_VENUE_CONFIRMATION,
            ])
            ->assertRedirect(route('dashboard.workflow-tasks.show', $task));

        $task->refresh();
        $trail = ApprovalTrail::firstOrFail();

        $this->assertSame('Venue confirmation evidence', data_get($task->metadata, 'revision.target.label'));
        $this->assertSame(CanonicalPpaAttachment::TYPE_VENUE_CONFIRMATION, data_get($task->metadata, 'revision.target.key'));
        $this->assertSame('Venue confirmation evidence', data_get($trail->metadata, 'revision_target.label'));
        $this->assertSame($actor->id, data_get($trail->metadata, 'actor.id'));
    }

    public function test_not_required_decision_is_limited_to_optional_steps(): void
    {
        $actor = $this->osaUser('osa.optional');
        $requiredStep = $this->step('required_review', 'Required Review', 1);
        $optionalStep = $this->step('optional_clearance', 'Optional Clearance', 2, isRequired: false);
        $this->publishVersion();
        [, $run] = $this->workflowRun($actor);

        $requiredTask = $this->task($run, $requiredStep, $actor, ProposalWorkflowTask::STATUS_AVAILABLE);
        $optionalTask = $this->task($run, $optionalStep, $actor, ProposalWorkflowTask::STATUS_AVAILABLE);

        $this->actingAs($actor)
            ->from(route('dashboard.workflow-tasks.show', $requiredTask))
            ->post(route('dashboard.workflow-tasks.decide', $requiredTask), [
                'decision' => ProposalWorkflowTask::DECISION_NOT_REQUIRED,
                'remarks' => 'This required step cannot be bypassed.',
            ])
            ->assertRedirect(route('dashboard.workflow-tasks.show', $requiredTask))
            ->assertSessionHasErrors('decision');

        $this->assertSame(ProposalWorkflowTask::STATUS_AVAILABLE, $requiredTask->fresh()->status);

        $this->actingAs($actor)
            ->post(route('dashboard.workflow-tasks.decide', $optionalTask), [
                'decision' => ProposalWorkflowTask::DECISION_NOT_REQUIRED,
                'remarks' => 'Venue clearance is not required for this online activity.',
            ])
            ->assertRedirect(route('dashboard.workflow-tasks.show', $optionalTask));

        $optionalTask->refresh();

        $this->assertSame(ProposalWorkflowTask::STATUS_NOT_REQUIRED, $optionalTask->status);
        $this->assertSame(ProposalWorkflowTask::DECISION_NOT_REQUIRED, $optionalTask->decision);
        $this->assertDatabaseHas('approval_trails', [
            'workflow_task_id' => $optionalTask->id,
            'decision' => ProposalWorkflowTask::DECISION_NOT_REQUIRED,
            'remarks' => 'Venue clearance is not required for this online activity.',
        ]);
    }

    public function test_rejected_and_revision_requested_decisions_update_task_run_and_proposal_state(): void
    {
        $actor = $this->osaUser('osa.outcomes');
        $step = $this->step('osa_review', 'OSA Review', 1);
        $this->publishVersion();

        [$rejectedEvent, $rejectedRun] = $this->workflowRun($actor);
        $rejectedTask = $this->task($rejectedRun, $step, $actor, ProposalWorkflowTask::STATUS_AVAILABLE);

        $this->actingAs($actor)
            ->post(route('dashboard.workflow-tasks.decide', $rejectedTask), [
                'decision' => ProposalWorkflowTask::DECISION_REJECTED,
                'remarks' => 'The proposal conflicts with institutional activity restrictions.',
            ])
            ->assertRedirect(route('dashboard.workflow-tasks.show', $rejectedTask));

        $this->assertSame(ProposalWorkflowTask::STATUS_REJECTED, $rejectedTask->fresh()->status);
        $this->assertSame(ProposalWorkflowRun::STATUS_REJECTED, $rejectedRun->fresh()->status);
        $this->assertSame(Event::STATUS_REJECTED, $rejectedEvent->fresh()->status);
        $this->assertDatabaseHas('approval_trails', [
            'workflow_task_id' => $rejectedTask->id,
            'decision' => ProposalWorkflowTask::DECISION_REJECTED,
            'remarks' => 'The proposal conflicts with institutional activity restrictions.',
        ]);

        [$revisionEvent, $revisionRun] = $this->workflowRun($actor);
        $revisionTask = $this->task($revisionRun, $step, $actor, ProposalWorkflowTask::STATUS_AVAILABLE, 'osa_revision_review');

        $this->actingAs($actor)
            ->post(route('dashboard.workflow-tasks.decide', $revisionTask), [
                'decision' => ProposalWorkflowTask::DECISION_REVISION_REQUESTED,
                'remarks' => 'Please attach the corrected venue confirmation before approval.',
                'revision_target' => 'attachment_requirement:'.CanonicalPpaAttachment::TYPE_VENUE_CONFIRMATION,
            ])
            ->assertRedirect(route('dashboard.workflow-tasks.show', $revisionTask));

        $this->assertSame(ProposalWorkflowTask::STATUS_REVISION_REQUESTED, $revisionTask->fresh()->status);
        $this->assertSame(ProposalWorkflowRun::STATUS_REVISION_REQUESTED, $revisionRun->fresh()->status);
        $this->assertSame(Event::STATUS_REVISION_REQUESTED, $revisionEvent->fresh()->status);
        $this->assertDatabaseHas('approval_trails', [
            'workflow_task_id' => $revisionTask->id,
            'decision' => ProposalWorkflowTask::DECISION_REVISION_REQUESTED,
            'remarks' => 'Please attach the corrected venue confirmation before approval.',
        ]);
    }

    public function test_approval_trails_are_immutable_after_creation(): void
    {
        $actor = $this->osaUser('osa.immutable');
        $step = $this->step('osa_review', 'OSA Review', 1);
        $this->publishVersion();
        [, $run] = $this->workflowRun($actor);
        $task = $this->task($run, $step, $actor, ProposalWorkflowTask::STATUS_AVAILABLE);

        $this->actingAs($actor)
            ->post(route('dashboard.workflow-tasks.decide', $task), [
                'decision' => ProposalWorkflowTask::DECISION_APPROVED,
                'remarks' => 'Looks complete.',
            ])
            ->assertRedirect(route('dashboard.workflow-tasks.show', $task));

        $trail = ApprovalTrail::firstOrFail();

        $updateWasBlocked = false;
        try {
            $trail->update(['remarks' => 'Changed later.']);
        } catch (DomainException) {
            $updateWasBlocked = true;
        }

        $deleteWasBlocked = false;
        try {
            $trail->delete();
        } catch (DomainException) {
            $deleteWasBlocked = true;
        }

        $this->assertTrue($updateWasBlocked);
        $this->assertTrue($deleteWasBlocked);
        $this->assertSame('Looks complete.', $trail->fresh()->remarks);
        $this->assertDatabaseCount('approval_trails', 1);

        $databaseUpdateWasBlocked = false;
        try {
            DB::table('approval_trails')->where('id', $trail->id)->update(['remarks' => 'Changed directly.']);
        } catch (QueryException) {
            $databaseUpdateWasBlocked = true;
        }

        $this->assertTrue($databaseUpdateWasBlocked);
        $this->assertSame('Looks complete.', $trail->fresh()->remarks);
    }

    private function osaUser(string $emailPrefix): User
    {
        $user = User::factory()->create([
            'email' => $emailPrefix.'@usm.edu.ph',
        ]);
        $user->assignRole('Super Admin (OSA)');

        return $user;
    }

    private function step(string $key, string $title, int $order, bool $isRequired = true): ProposalProcessStep
    {
        return ProposalProcessStep::create([
            'template_version_id' => $this->version->id,
            'step_key' => $key,
            'title' => $title,
            'step_type' => ProposalProcessStep::TYPE_APPROVAL,
            'phase' => 'approval',
            'display_order' => $order,
            'is_required' => $isRequired,
            'condition_config' => [
                'assigned_role' => 'osa',
                'assigned_scope' => 'university',
            ],
        ]);
    }

    private function dependency(ProposalProcessStep $step, ProposalProcessStep $prerequisite): ProposalProcessStepDependency
    {
        return ProposalProcessStepDependency::create([
            'step_id' => $step->id,
            'prerequisite_step_id' => $prerequisite->id,
        ]);
    }

    private function publishVersion(): void
    {
        $this->version->update([
            'status' => ProposalProcessTemplateVersion::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);
    }

    /**
     * @return array{0: Event, 1: ProposalWorkflowRun}
     */
    private function workflowRun(User $actor): array
    {
        $event = Event::create([
            'organization_id' => $this->organization->id,
            'school_year_id' => $this->schoolYear->id,
            'title' => 'Workflow Decision Event',
            'proposal_category' => 'activity',
            'status' => Event::STATUS_UNDER_REVIEW,
            'lifecycle_phase' => 'scheduled',
            'event_domain' => 'student_affairs',
            'start_date' => today()->addMonth(),
            'end_date' => today()->addMonth(),
            'location_type' => 'on-campus',
            'organization_name_snapshot' => $this->organization->name,
            'proponent_name_snapshot' => 'Workflow Tester',
        ]);

        $run = ProposalWorkflowRun::create([
            'event_id' => $event->id,
            'template_version_id' => $this->version->id,
            'status' => ProposalWorkflowRun::STATUS_IN_PROGRESS,
            'started_by' => $actor->id,
            'started_at' => now(),
            'submitted_at' => now(),
        ]);

        return [$event, $run];
    }

    private function task(
        ProposalWorkflowRun $run,
        ProposalProcessStep $step,
        User $assignee,
        string $status,
        ?string $stepKey = null
    ): ProposalWorkflowTask {
        return ProposalWorkflowTask::create([
            'workflow_run_id' => $run->id,
            'process_step_id' => $step->id,
            'status' => $status,
            'display_order' => $step->display_order,
            'step_key' => $stepKey ?? $step->step_key,
            'step_type' => $step->step_type,
            'title' => $step->title,
            'assigned_user_id' => $assignee->id,
            'assigned_role' => 'osa',
            'assigned_scope' => 'university',
            'available_at' => $status === ProposalWorkflowTask::STATUS_AVAILABLE ? now() : null,
            'metadata' => [
                'assignment' => [
                    'role_key' => 'osa',
                    'scope_key' => 'university',
                    'scope' => [
                        'school_year_id' => $this->schoolYear->id,
                        'organization_id' => $this->organization->id,
                        'organization_name' => $this->organization->name,
                        'event_domain' => 'student_affairs',
                    ],
                    'assigned_user_snapshot' => [
                        'id' => $assignee->id,
                        'name' => $assignee->name,
                        'email' => $assignee->email,
                    ],
                    'resolved_at' => now()->toIso8601String(),
                ],
            ],
        ]);
    }
}
