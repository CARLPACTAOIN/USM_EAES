<?php

namespace Tests\Feature;

use App\Models\ApprovalTrail;
use App\Models\CanonicalPpaAttachment;
use App\Models\College;
use App\Models\Event;
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
