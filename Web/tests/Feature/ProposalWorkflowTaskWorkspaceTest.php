<?php

namespace Tests\Feature;

use App\Models\AdminAssignment;
use App\Models\ApprovalTrail;
use App\Models\CanonicalPpaAttachment;
use App\Models\CanonicalPpaPacket;
use App\Models\College;
use App\Models\Department;
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
use App\Models\Venue;
use App\Services\AdminAssignmentService;
use App\Services\EventNotificationService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProposalWorkflowTaskWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private SchoolYear $schoolYear;

    private University $university;

    private College $college;

    private Department $department;

    private Organization $organization;

    private User $organizer;

    private User $approver;

    private User $osaAdmin;

    private User $unauthorizedUser;

    private ProposalProcessTemplateVersion $templateVersion;

    private ProposalProcessStep $step1;

    private ProposalProcessStep $step2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->university = University::create([
            'name' => 'University of Southern Mindanao',
            'domain' => 'usm.edu.ph',
        ]);

        $this->schoolYear = SchoolYear::create([
            'label' => '2026-2027',
            'starts_on' => today()->subMonth(),
            'ends_on' => today()->addMonths(10),
            'status' => SchoolYear::STATUS_ACTIVE,
            'activated_at' => now(),
        ]);

        $this->college = College::create([
            'university_id' => $this->university->id,
            'name' => 'College of Computing Studies',
            'code' => 'CCS',
        ]);

        $this->department = Department::create([
            'college_id' => $this->college->id,
            'name' => 'Department of Information Technology',
            'code' => 'DIT',
            'status' => 'active',
        ]);

        $this->organization = Organization::create([
            'college_id' => $this->college->id,
            'department_id' => $this->department->id,
            'name' => 'Information Technology Society',
            'acronym' => 'ITS',
            'type' => 'society',
            'status' => 'active',
        ]);

        $this->organizer = User::factory()->create([
            'name' => 'IT Society President',
            'email' => 'its.president@usm.edu.ph',
            'college_id' => $this->college->id,
            'department_id' => $this->department->id,
        ]);
        $this->organizer->assignRole('Society Admin');

        app(AdminAssignmentService::class)->createAssignment(
            user: $this->organizer,
            roleName: 'Society Admin',
            organization: $this->organization,
            college: $this->college,
            academicYear: $this->schoolYear->label,
            termStart: today()->subMonth()->toDateString(),
            termEnd: today()->addYear()->toDateString(),
            positionTitle: 'President',
            approvedBy: $this->organizer,
            isPrimaryAdmin: true,
        );

        $this->approver = User::factory()->create([
            'name' => 'Faculty Adviser Dr. Cruz',
            'email' => 'adviser.cruz@usm.edu.ph',
            'user_type' => 'faculty',
        ]);
        $this->approver->assignRole('Faculty');

        $this->osaAdmin = User::factory()->create([
            'name' => 'OSA Director Santos',
            'email' => 'director.osa@usm.edu.ph',
        ]);
        $this->osaAdmin->assignRole('Super Admin (OSA)');

        AdminAssignment::create([
            'user_id' => $this->osaAdmin->id,
            'role_name' => 'Super Admin (OSA)',
            'position_title' => 'Director',
            'school_year_id' => $this->schoolYear->id,
            'academic_year' => $this->schoolYear->label,
            'status' => AdminAssignment::STATUS_ACTIVE,
            'is_primary_admin' => true,
            'approved_at' => now(),
        ]);

        $this->unauthorizedUser = User::factory()->create([
            'name' => 'Unauthorized Student',
            'email' => 'unauthorized.student@usm.edu.ph',
        ]);
        $this->unauthorizedUser->assignRole('Student');

        $template = ProposalProcessTemplate::create([
            'name' => 'PPA Approval Workflow Template',
            'status' => ProposalProcessTemplate::STATUS_ACTIVE,
        ]);

        $this->templateVersion = ProposalProcessTemplateVersion::create([
            'template_id' => $template->id,
            'version_number' => 1,
            'status' => ProposalProcessTemplateVersion::STATUS_DRAFT,
        ]);

        $this->step1 = ProposalProcessStep::create([
            'template_version_id' => $this->templateVersion->id,
            'step_key' => 'adviser_endorsement',
            'title' => 'Faculty Adviser Endorsement',
            'step_type' => ProposalProcessStep::TYPE_APPROVAL,
            'phase' => 'approval',
            'display_order' => 1,
            'is_required' => true,
            'condition_config' => [
                'assigned_role' => 'adviser',
                'assigned_scope' => 'organization',
            ],
        ]);

        $this->step2 = ProposalProcessStep::create([
            'template_version_id' => $this->templateVersion->id,
            'step_key' => 'osa_clearance',
            'title' => 'OSA Administrative Clearance',
            'step_type' => ProposalProcessStep::TYPE_APPROVAL,
            'phase' => 'approval',
            'display_order' => 2,
            'is_required' => true,
            'condition_config' => [
                'assigned_role' => 'osa',
                'assigned_scope' => 'university',
            ],
        ]);

        $this->step3 = ProposalProcessStep::create([
            'template_version_id' => $this->templateVersion->id,
            'step_key' => 'security_clearance',
            'title' => 'Campus Security Assessment',
            'step_type' => ProposalProcessStep::TYPE_APPROVAL,
            'phase' => 'approval',
            'display_order' => 3,
            'is_required' => false,
            'condition_config' => [
                'assigned_role' => 'security',
                'assigned_scope' => 'university',
                'allow_not_required' => true,
            ],
        ]);

        ProposalProcessStepDependency::create([
            'step_id' => $this->step2->id,
            'prerequisite_step_id' => $this->step1->id,
        ]);

        $this->templateVersion->update([
            'status' => ProposalProcessTemplateVersion::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);
    }

    public function test_assigned_approver_can_view_active_task_workspace_with_complete_identity_packet_and_decision_controls(): void
    {
        [$event, $run] = $this->createEventAndRun();
        $task = $this->createTask($run, $this->step1, $this->approver, ProposalWorkflowTask::STATUS_AVAILABLE);
        $this->createTask($run, $this->step2, $this->osaAdmin, ProposalWorkflowTask::STATUS_BLOCKED);

        $response = $this->actingAs($this->approver)
            ->get(route('dashboard.workflow-tasks.show', $task));

        $response->assertOk();

        // 1. Header & Identity
        $response->assertSee('Faculty Adviser Endorsement');
        $response->assertSee('Workflow Task #1');
        $response->assertSee('Available');
        $response->assertSee('Tech Leadership Summit 2026');
        $response->assertSee('Information Technology Society');
        $response->assertSee('(ITS)');
        $response->assertSee('Activity');
        $response->assertSee('Under Review');
        $response->assertSee('IT Society President');
        $response->assertSee('Guild Officer Lead');
        $response->assertSee('president@its.usm.edu.ph');
        $response->assertSee('USM Commercial Center Hall');
        $response->assertSee('PHP 15,000.00');
        $response->assertSee('ITS Membership Dues');
        $response->assertSee('150 participants');

        // 2. Canonical Packet Sections
        $response->assertSee('Canonical PPA Packet Details');
        $response->assertSee('To build technical and organizational competency for IT majors.');
        $response->assertSee('Provide hands-on workshops');
        $response->assertSee('Conduct mentorship roundtables');
        $response->assertSee('Hybrid plenary session followed by breakout coding challenges.');
        $response->assertSee('9:00 AM');
        $response->assertSee('Opening');
        $response->assertSee('BSIT 1st to 4th Year Students');
        $response->assertSee('Trained student leaders and submitted technical mini-projects.');

        // 3. Supporting Attachments & Venue Evidence
        $response->assertSee('Supporting Attachments & Evidence', false);
        $response->assertSee('official_proposal_doc.pdf');
        $response->assertSee('venue_permit_evidence.pdf');
        $response->assertSee('VCONF-2026-001');
        $response->assertSee('activity_budget_plan.pdf');
        $response->assertSee('Uploaded by IT Society President');

        // 4. Workflow Process Rail
        $response->assertSee('Workflow Process Rail');
        $response->assertSee('Current Workspace');
        $response->assertSee('OSA Administrative Clearance');

        // 5. Decision Controls
        $response->assertSee('Decision Controls');
        $response->assertSee('action="'.route('dashboard.workflow-tasks.decide', $task).'"', false);
        $response->assertSee('Review Outcome');
        $response->assertSee('Approved');
        $response->assertSee('Revision Requested');
        $response->assertSee('Rejected');
        $response->assertDontSee('Not Required'); // Mandatory step should not display Not Required option
        $response->assertSee('Revision Target');
        $response->assertSee('Remarks');
        $response->assertSee('Record Decision');

        // 6. Preview Navigation Links
        $response->assertSee(route('dashboard.events.proposal-preview', $event));
        $response->assertSee(route('dashboard.events.permit-preview', $event));
    }

    public function test_assigned_approver_can_view_historically_completed_task_workspace_with_approval_trail_and_no_decision_form(): void
    {
        [$event, $run] = $this->createEventAndRun();
        $task = $this->createTask($run, $this->step1, $this->approver, ProposalWorkflowTask::STATUS_APPROVED);
        $task->update([
            'decided_by_user_id' => $this->approver->id,
            'decided_at' => now(),
            'completed_at' => now(),
            'remarks' => 'Everything looks comprehensive and compliant with department policies.',
        ]);

        $trail = ApprovalTrail::create([
            'proposal_id' => $event->id,
            'workflow_run_id' => $run->id,
            'workflow_task_id' => $task->id,
            'actor_id' => $this->approver->id,
            'reference_id' => 'TRL-COMPLETED-001',
            'decision' => ProposalWorkflowTask::DECISION_APPROVED,
            'previous_status' => ProposalWorkflowTask::STATUS_AVAILABLE,
            'resulting_status' => ProposalWorkflowTask::STATUS_APPROVED,
            'assigned_role' => 'adviser',
            'assigned_scope' => 'organization',
            'remarks' => 'Everything looks comprehensive and compliant with department policies.',
            'decided_at' => now(),
        ]);

        $response = $this->actingAs($this->approver)
            ->get(route('dashboard.workflow-tasks.show', $task));

        $response->assertOk();

        // Should see completion summary
        $response->assertSee('Decision Completed');
        $response->assertSee('Approved');
        $response->assertSee($this->approver->name);
        $response->assertSee('Everything looks comprehensive and compliant with department policies.');
        $response->assertSee('This workflow task is completed and permanently recorded in the institutional Approval Trail.');

        // Should see Approval Trail
        $response->assertSee('Approval Trail');
        $response->assertSee('TRL-COMPLETED-001');

        // Should NOT render decision form
        $response->assertDontSee('action="'.route('dashboard.workflow-tasks.decide', $task).'"', false);
        $response->assertDontSee('Record Decision');
    }

    public function test_assigned_approver_views_blocked_task_with_prerequisite_lock_notice(): void
    {
        [$event, $run] = $this->createEventAndRun();
        $this->createTask($run, $this->step1, $this->approver, ProposalWorkflowTask::STATUS_AVAILABLE);
        $blockedTask = $this->createTask($run, $this->step2, $this->osaAdmin, ProposalWorkflowTask::STATUS_BLOCKED);

        $response = $this->actingAs($this->osaAdmin)
            ->get(route('dashboard.workflow-tasks.show', $blockedTask));

        $response->assertOk();
        $response->assertSee('Decision Controls Locked');
        $response->assertSee('This step cannot be decided until preceding prerequisite approvals have been completed.');
        $response->assertSee('Prerequisite Steps:');
        $response->assertSee('Faculty Adviser Endorsement');
        $response->assertDontSee('Record Decision');
    }

    public function test_unauthorized_cross_tenant_user_is_forbidden_from_viewing_task(): void
    {
        [$event, $run] = $this->createEventAndRun();
        $task = $this->createTask($run, $this->step1, $this->approver, ProposalWorkflowTask::STATUS_AVAILABLE);

        $response = $this->actingAs($this->unauthorizedUser)
            ->get(route('dashboard.workflow-tasks.show', $task));

        $response->assertForbidden();
    }

    public function test_osa_administrator_can_inspect_active_and_completed_tasks_assigned_to_others(): void
    {
        [$event, $run] = $this->createEventAndRun();
        $task = $this->createTask($run, $this->step1, $this->approver, ProposalWorkflowTask::STATUS_AVAILABLE);

        // OSA inspecting active task
        $response = $this->actingAs($this->osaAdmin)
            ->get(route('dashboard.workflow-tasks.show', $task));

        $response->assertOk();
        $response->assertSee('Administrative Inspection');
        $response->assertSee('You are viewing this task under Super Admin (OSA) authority.');
        $response->assertSee($this->approver->name);
        // OSA has reassignment control for active tasks
        $response->assertSee('Reassign Task');

        // Complete the task
        $task->update([
            'status' => ProposalWorkflowTask::STATUS_APPROVED,
            'decided_by_user_id' => $this->approver->id,
            'decided_at' => now(),
            'completed_at' => now(),
            'remarks' => 'Approved by adviser.',
        ]);

        // OSA inspecting completed task
        $completedResponse = $this->actingAs($this->osaAdmin)
            ->get(route('dashboard.workflow-tasks.show', $task));

        $completedResponse->assertOk();
        $completedResponse->assertSee('Decision Completed');
        $completedResponse->assertSee('Approved');
    }

    public function test_assigned_approver_can_access_previews_and_attachments_while_unauthorized_is_forbidden(): void
    {
        Storage::fake();

        // Store dummy files matching createEventAndRun
        Storage::put('proposals/sample_proposal.pdf', 'dummy proposal pdf content');
        Storage::put('attachments/budget_sample.pdf', 'dummy budget pdf content');
        Storage::put('venues/sample_venue.pdf', 'dummy venue proof content');

        [$event, $run] = $this->createEventAndRun();
        $attachment = $event->canonicalPpaPacket->attachments()->first();

        $task = $this->createTask($run, $this->step1, $this->approver, ProposalWorkflowTask::STATUS_AVAILABLE);

        // 1. Authorized Approver Checks
        $this->actingAs($this->approver)
            ->get(route('dashboard.events.proposal-preview', $event))
            ->assertOk();

        $this->actingAs($this->approver)
            ->get(route('dashboard.events.permit-preview', $event))
            ->assertOk();

        $this->actingAs($this->approver)
            ->get(route('dashboard.events.proposal-document', $event))
            ->assertOk()
            ->assertDownload('official_proposal_doc.pdf');

        $this->actingAs($this->approver)
            ->get(route('dashboard.events.ppa-attachments', [$event, $attachment]))
            ->assertOk()
            ->assertDownload('activity_budget_plan.pdf');

        $this->actingAs($this->approver)
            ->get(route('dashboard.events.venue-evidence', $event))
            ->assertOk()
            ->assertDownload('venue_permit_evidence.pdf');

        // 2. Unauthorized User Checks (Must be strictly 403 Forbidden)
        $this->actingAs($this->unauthorizedUser)
            ->get(route('dashboard.events.proposal-preview', $event))
            ->assertForbidden();

        $this->actingAs($this->unauthorizedUser)
            ->get(route('dashboard.events.permit-preview', $event))
            ->assertForbidden();

        $this->actingAs($this->unauthorizedUser)
            ->get(route('dashboard.events.proposal-document', $event))
            ->assertForbidden();

        $this->actingAs($this->unauthorizedUser)
            ->get(route('dashboard.events.ppa-attachments', [$event, $attachment]))
            ->assertForbidden();

        $this->actingAs($this->unauthorizedUser)
            ->get(route('dashboard.events.venue-evidence', $event))
            ->assertForbidden();
    }

    public function test_reject_and_revision_requested_decisions_require_remarks_and_target(): void
    {
        [$event, $run] = $this->createEventAndRun();
        $task = $this->createTask($run, $this->step1, $this->approver, ProposalWorkflowTask::STATUS_AVAILABLE);

        // 1. Reject without remarks should fail validation
        $rejectResponse = $this->actingAs($this->approver)
            ->post(route('dashboard.workflow-tasks.decide', $task), [
                'decision' => ProposalWorkflowTask::DECISION_REJECTED,
                'remarks' => '',
            ]);

        $rejectResponse->assertSessionHasErrors(['remarks']);

        // 2. Revision requested without remarks should fail validation
        $revisionResponse = $this->actingAs($this->approver)
            ->post(route('dashboard.workflow-tasks.decide', $task), [
                'decision' => ProposalWorkflowTask::DECISION_REVISION_REQUESTED,
                'revision_target' => 'packet:general_information',
                'remarks' => '',
            ]);

        $revisionResponse->assertSessionHasErrors(['remarks']);

        // 3. Revision requested without target should fail validation
        $noTargetResponse = $this->actingAs($this->approver)
            ->post(route('dashboard.workflow-tasks.decide', $task), [
                'decision' => ProposalWorkflowTask::DECISION_REVISION_REQUESTED,
                'revision_target' => '',
                'remarks' => 'Please update schedule.',
            ]);

        $noTargetResponse->assertSessionHasErrors(['revision_target']);

        // 4. Approved with optional remarks succeeds
        $approveResponse = $this->actingAs($this->approver)
            ->post(route('dashboard.workflow-tasks.decide', $task), [
                'decision' => ProposalWorkflowTask::DECISION_APPROVED,
                'remarks' => 'Endorsed.',
            ]);

        $approveResponse->assertRedirect(route('dashboard.workflow-tasks.show', $task));
        $this->assertDatabaseHas('proposal_workflow_tasks', [
            'id' => $task->id,
            'status' => ProposalWorkflowTask::STATUS_APPROVED,
        ]);
    }

    public function test_optional_step_renders_not_required_control(): void
    {
        [$event, $run] = $this->createEventAndRun();

        $optionalTask = $this->createTask($run, $this->step3, $this->approver, ProposalWorkflowTask::STATUS_AVAILABLE);

        $response = $this->actingAs($this->approver)
            ->get(route('dashboard.workflow-tasks.show', $optionalTask));

        $response->assertOk();
        $response->assertSee('value="not_required"', false);
        $response->assertSee('Not Required');
    }

    public function test_notifications_route_directly_to_task_workspace(): void
    {
        [$event, $run] = $this->createEventAndRun();
        $task = $this->createTask($run, $this->step1, $this->approver, ProposalWorkflowTask::STATUS_AVAILABLE);

        $notificationService = app(EventNotificationService::class);
        $notificationService->notifyWorkflowTaskAssigned($task);

        $notification = $this->approver->notifications()->latest()->first();
        $this->assertNotNull($notification);
        $this->assertSame(route('dashboard.workflow-tasks.show', $task), $notification->data['action_url']);
        $this->assertSame('workflow_task_assigned', $notification->data['kind']);
    }

    /**
     * @return array{0: Event, 1: ProposalWorkflowRun}
     */
    private function createEventAndRun(): array
    {
        $venue = Venue::create([
            'name' => 'USM Commercial Center Hall',
            'campus_location' => 'Main Campus',
            'managing_office' => 'Commercial Services',
            'is_active' => true,
        ]);

        $event = Event::create([
            'organization_id' => $this->organization->id,
            'school_year_id' => $this->schoolYear->id,
            'title' => 'Tech Leadership Summit 2026',
            'proposal_category' => 'activity',
            'status' => Event::STATUS_UNDER_REVIEW,
            'lifecycle_phase' => 'scheduled',
            'event_domain' => 'student_affairs',
            'start_date' => today()->addDays(14),
            'end_date' => today()->addDays(14),
            'location_type' => 'on-campus',
            'venue_id' => $venue->id,
            'expected_attendees' => 150,
            'organization_name_snapshot' => $this->organization->name,
            'proponent_name_snapshot' => 'IT Society President',
            'venue_confirmation_reference' => 'VCONF-2026-001',
            'proposal_document_path' => 'proposals/sample_proposal.pdf',
            'proposal_document_original_name' => 'official_proposal_doc.pdf',
            'venue_confirmation_document_path' => 'venues/sample_venue.pdf',
            'venue_confirmation_document_original_name' => 'venue_permit_evidence.pdf',
        ]);

        $packet = CanonicalPpaPacket::create([
            'event_id' => $event->id,
            'title' => 'Tech Leadership Summit 2026',
            'proposal_category' => 'activity',
            'proponent_name' => 'IT Society President',
            'proponent_position' => 'Guild Officer Lead',
            'proponent_email' => 'president@its.usm.edu.ph',
            'proponent_contact_number' => '+63 912 345 6789',
            'location_type' => 'on-campus',
            'location_details' => 'USM Commercial Center Hall',
            'venue_confirmation_reference_number' => 'VCONF-2026-001',
            'budget_cost' => 15000.00,
            'source_of_fund' => 'ITS Membership Dues',
            'delegate_count' => 150,
            'objectives' => 'To build technical and organizational competency for IT majors.',
            'general_objective' => 'To build technical and organizational competency for IT majors.',
            'specific_objectives' => [
                'Provide hands-on workshops',
                'Conduct mentorship roundtables',
            ],
            'methodology' => 'Hybrid plenary session followed by breakout coding challenges.',
            'major_activities' => [
                'Keynote Address',
                'Technical Breakouts',
            ],
            'program_activities' => [
                [
                    'time' => '9:00 AM',
                    'activity' => 'Opening & Keynote',
                ],
            ],
            'beneficiaries' => 'BSIT 1st to 4th Year Students',
            'expected_output' => 'Trained student leaders and submitted technical mini-projects.',
        ]);

        CanonicalPpaAttachment::create([
            'canonical_ppa_packet_id' => $packet->id,
            'attachment_type' => CanonicalPpaAttachment::TYPE_SUPPORTING,
            'original_name' => 'activity_budget_plan.pdf',
            'path' => 'attachments/budget_sample.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 12345,
            'uploaded_by_user_id' => $this->organizer->id,
        ]);

        $run = ProposalWorkflowRun::create([
            'event_id' => $event->id,
            'template_version_id' => $this->templateVersion->id,
            'status' => ProposalWorkflowRun::STATUS_IN_PROGRESS,
            'started_by' => $this->organizer->id,
            'started_at' => now(),
            'submitted_at' => now(),
        ]);

        return [$event, $run];
    }

    private function createTask(
        ProposalWorkflowRun $run,
        ProposalProcessStep $step,
        User $assignee,
        string $status
    ): ProposalWorkflowTask {
        return ProposalWorkflowTask::create([
            'workflow_run_id' => $run->id,
            'process_step_id' => $step->id,
            'status' => $status,
            'display_order' => $step->display_order,
            'step_key' => $step->step_key,
            'step_type' => $step->step_type,
            'title' => $step->title,
            'assigned_user_id' => $assignee->id,
            'assigned_role' => data_get($step->condition_config, 'assigned_role', 'approver'),
            'assigned_scope' => data_get($step->condition_config, 'assigned_scope', 'organization'),
            'available_at' => $status === ProposalWorkflowTask::STATUS_AVAILABLE ? now() : null,
            'metadata' => [
                'assignment' => [
                    'assigned_user_snapshot' => [
                        'id' => $assignee->id,
                        'name' => $assignee->name,
                        'email' => $assignee->email,
                    ],
                ],
            ],
        ]);
    }
}
