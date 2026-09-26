<?php

namespace Tests\Feature;

use App\Models\AdminAssignment;
use App\Models\College;
use App\Models\Department;
use App\Models\Event;
use App\Models\Organization;
use App\Models\ProposalProcessStep;
use App\Models\ProposalProcessTemplate;
use App\Models\ProposalProcessTemplateVersion;
use App\Models\ProposalWorkflowRun;
use App\Models\ProposalWorkflowTask;
use App\Models\SchoolYear;
use App\Models\User;
use App\Services\ProposalWorkflowTaskService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProposalWorkflowTaskInboxTest extends TestCase
{
    use RefreshDatabase;

    private ProposalProcessStep $step;

    private SchoolYear $schoolYear;

    private College $college;

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

        $this->college = College::factory()->create();
        $this->organization = Organization::create([
            'college_id' => $this->college->id,
            'name' => 'Workflow Test Society',
            'acronym' => 'WTS',
            'type' => 'society',
            'status' => 'active',
        ]);

        $template = ProposalProcessTemplate::create([
            'name' => 'Workflow Inbox Test Template',
            'status' => ProposalProcessTemplate::STATUS_ACTIVE,
        ]);

        $version = ProposalProcessTemplateVersion::create([
            'template_id' => $template->id,
            'version_number' => 1,
            'status' => ProposalProcessTemplateVersion::STATUS_DRAFT,
        ]);

        $this->step = ProposalProcessStep::create([
            'template_version_id' => $version->id,
            'step_key' => 'osa_review',
            'title' => 'OSA Review',
            'step_type' => ProposalProcessStep::TYPE_APPROVAL,
            'display_order' => 1,
            'is_required' => true,
            'condition_config' => [
                'assigned_role' => 'osa',
                'assigned_scope' => 'university',
            ],
        ]);

        $version->update([
            'status' => ProposalProcessTemplateVersion::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);
    }

    public function test_approver_inbox_displays_only_tasks_assigned_to_the_authenticated_user(): void
    {
        $osa = $this->osaUser('osa.one');
        $otherOsa = $this->osaUser('osa.two');

        $assignedTask = $this->taskFor($osa, 'Assigned OSA Review', 'osa');
        $otherTask = $this->taskFor($otherOsa, 'Other OSA Review', 'osa');

        $response = $this->actingAs($osa)
            ->get(route('dashboard.workflow-tasks.index'));

        $response->assertOk();
        $response->assertSee($assignedTask->title);
        $response->assertDontSee($otherTask->title);
    }

    public function test_cross_approver_task_detail_access_is_denied(): void
    {
        $assignedApprover = User::factory()->create(['email' => 'adviser.one@usm.edu.ph']);
        $assignedApprover->assignRole('Faculty');
        $otherApprover = User::factory()->create(['email' => 'adviser.two@usm.edu.ph']);
        $otherApprover->assignRole('Faculty');

        $task = $this->taskFor($assignedApprover, 'Adviser Endorsement', 'adviser');

        $this->actingAs($otherApprover)
            ->get(route('dashboard.workflow-tasks.show', $task))
            ->assertForbidden();
    }

    public function test_osa_reassignment_records_assignment_history(): void
    {
        $actor = $this->osaUser('osa.actor');
        $replacement = $this->osaUser('osa.replacement');
        $task = $this->taskFor($actor, 'OSA Final Approval', 'osa');

        $response = $this->actingAs($actor)
            ->post(route('dashboard.workflow-tasks.reassign', $task), [
                'new_assignee_id' => $replacement->id,
                'reason' => 'Original approver is unavailable for the scheduled review window.',
            ]);

        $response->assertRedirect(route('dashboard.workflow-tasks.show', $task));
        $this->assertSame($replacement->id, $task->fresh()->assigned_user_id);

        $this->assertDatabaseHas('workflow_task_assignment_history', [
            'workflow_task_id' => $task->id,
            'former_assignee_id' => $actor->id,
            'new_assignee_id' => $replacement->id,
            'actor_id' => $actor->id,
            'reason' => 'Original approver is unavailable for the scheduled review window.',
        ]);
    }

    public function test_department_chair_replacement_eligibility_preserves_department_scope(): void
    {
        $department = Department::factory()->create([
            'college_id' => $this->college->id,
            'name' => 'Department of Scoped Computing',
            'code' => 'DSC',
        ]);
        $otherDepartment = Department::factory()->create([
            'college_id' => $this->college->id,
            'name' => 'Department of Other Computing',
            'code' => 'DOC',
        ]);
        $chair = User::factory()->create(['email' => 'chair.scope@usm.edu.ph']);
        $otherChair = User::factory()->create(['email' => 'chair.other@usm.edu.ph']);

        $this->adminAssignment($chair, 'Department Chairperson', $department->id);
        $this->adminAssignment($otherChair, 'Department Chairperson', $otherDepartment->id, isPrimary: false);

        $task = $this->taskFor($chair, 'Department Chair Review', 'dept_chair');
        $metadata = $task->metadata;
        data_set($metadata, 'assignment.scope.department_id', $department->id);
        data_set($metadata, 'assignment.scope.college_id', $this->college->id);
        $task->update(['metadata' => $metadata]);

        $eligibleIds = app(ProposalWorkflowTaskService::class)
            ->eligibleReplacements($task->fresh())
            ->pluck('id');

        $this->assertTrue($eligibleIds->contains($chair->id));
        $this->assertFalse($eligibleIds->contains($otherChair->id));
    }

    private function osaUser(string $emailPrefix): User
    {
        $user = User::factory()->create([
            'email' => $emailPrefix.'@usm.edu.ph',
        ]);
        $user->assignRole('Super Admin (OSA)');

        return $user;
    }

    private function taskFor(User $assignee, string $title, string $roleKey): ProposalWorkflowTask
    {
        $event = Event::create([
            'organization_id' => $this->organization->id,
            'school_year_id' => $this->schoolYear->id,
            'title' => $title.' Event',
            'proposal_category' => 'activity',
            'status' => Event::STATUS_SUBMITTED,
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
            'template_version_id' => $this->step->template_version_id,
            'status' => ProposalWorkflowRun::STATUS_IN_PROGRESS,
            'started_by' => $assignee->id,
            'started_at' => now(),
            'submitted_at' => now(),
        ]);

        return ProposalWorkflowTask::create([
            'workflow_run_id' => $run->id,
            'process_step_id' => $this->step->id,
            'status' => ProposalWorkflowTask::STATUS_AVAILABLE,
            'display_order' => 1,
            'step_key' => str($title)->slug('_')->toString(),
            'step_type' => ProposalProcessStep::TYPE_APPROVAL,
            'title' => $title,
            'assigned_user_id' => $assignee->id,
            'assigned_role' => $roleKey,
            'assigned_scope' => 'university',
            'available_at' => now(),
            'metadata' => [
                'assignment' => [
                    'role_key' => $roleKey,
                    'scope_key' => 'university',
                    'scope' => [
                        'school_year_id' => $this->schoolYear->id,
                        'organization_id' => $this->organization->id,
                        'organization_name' => $this->organization->name,
                        'college_id' => $this->organization->college_id,
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

    private function adminAssignment(User $user, string $roleName, string $departmentId, bool $isPrimary = true): AdminAssignment
    {
        return AdminAssignment::create([
            'user_id' => $user->id,
            'role_name' => $roleName,
            'position_title' => $roleName,
            'college_id' => $this->college->id,
            'department_id' => $departmentId,
            'school_year_id' => $this->schoolYear->id,
            'academic_year' => $this->schoolYear->label,
            'status' => AdminAssignment::STATUS_ACTIVE,
            'is_primary_admin' => $isPrimary,
            'approved_at' => now(),
        ]);
    }
}
