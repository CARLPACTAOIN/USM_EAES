<?php

namespace Tests\Feature;

use App\Models\AdminAssignment;
use App\Models\ApprovalTrail;
use App\Models\CanonicalPpaPacket;
use App\Models\College;
use App\Models\Department;
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
use App\Notifications\EventWorkflowNotification;
use App\Services\AdminAssignmentService;
use App\Services\EventNotificationService;
use App\Services\ProposalWorkflowTaskService;
use Database\Seeders\RolePermissionSeeder;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProposalWorkflowNotificationTest extends TestCase
{
    use RefreshDatabase;

    private SchoolYear $schoolYear;

    private University $university;

    private College $college;

    private Department $department;

    private Organization $organization;

    private User $organizer;

    private User $approver1;

    private User $approver2;

    private ProposalProcessTemplateVersion $templateVersion;

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
            'name' => 'Department of Computer Science',
            'code' => 'DCS',
            'status' => 'active',
        ]);

        $this->organization = Organization::create([
            'college_id' => $this->college->id,
            'department_id' => $this->department->id,
            'name' => 'Computer Science Guild',
            'acronym' => 'CSG',
            'type' => 'society',
            'status' => 'active',
        ]);

        $this->organizer = User::factory()->create([
            'name' => 'Organizer Officer',
            'email' => 'organizer.workflow@usm.edu.ph',
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
            positionTitle: 'Guild President',
            approvedBy: $this->organizer,
            isPrimaryAdmin: true,
        );

        $this->approver1 = User::factory()->create([
            'name' => 'Faculty Reviewer',
            'email' => 'approver1.workflow@usm.edu.ph',
            'user_type' => 'faculty',
        ]);
        $this->approver1->assignRole('Faculty');

        $this->approver2 = User::factory()->create([
            'name' => 'OSA Director',
            'email' => 'approver2.workflow@usm.edu.ph',
        ]);
        $this->approver2->assignRole('Super Admin (OSA)');

        AdminAssignment::create([
            'user_id' => $this->approver2->id,
            'role_name' => 'Super Admin (OSA)',
            'position_title' => 'OSA Director',
            'school_year_id' => $this->schoolYear->id,
            'academic_year' => $this->schoolYear->label,
            'status' => AdminAssignment::STATUS_ACTIVE,
            'is_primary_admin' => true,
            'approved_at' => now(),
        ]);

        $template = ProposalProcessTemplate::create([
            'name' => 'Standard CSG Workflow',
            'status' => ProposalProcessTemplate::STATUS_ACTIVE,
        ]);

        $this->templateVersion = ProposalProcessTemplateVersion::create([
            'template_id' => $template->id,
            'version_number' => 1,
            'status' => ProposalProcessTemplateVersion::STATUS_DRAFT,
        ]);
    }

    public function test_assignment_notifications_emitted_to_assignees_on_workflow_task_assignment(): void
    {
        $event = $this->createEvent();
        $run = $this->createWorkflowRun($event);
        $step = $this->createStep('adviser_endorsement', 'Adviser Endorsement', 1);
        $this->publishTemplate();

        $task = $this->createWorkflowTask($run, $step, $this->approver1, ProposalWorkflowTask::STATUS_AVAILABLE);

        $notificationService = app(EventNotificationService::class);
        $sentCount = $notificationService->notifyWorkflowTaskAssigned($task);

        $this->assertSame(1, $sentCount);

        $notification = $this->approver1->fresh()->notifications()
            ->where('type', EventWorkflowNotification::class)
            ->first();

        $this->assertNotNull($notification);
        $data = $notification->data;
        $this->assertSame('workflow_task_assigned', $data['kind']);
        $this->assertSame(route('dashboard.workflow-tasks.show', $task), $data['link']);
        $this->assertSame($event->id, $data['event_id']);
        $this->assertSame($task->id, $data['workflow_task_id']);
        $this->assertTrue($data['needs_action']);
        $this->assertSame("workflow_task_assigned:{$task->id}:{$this->approver1->id}", $data['notification_key']);
    }

    public function test_submission_notification_emitted_to_organizers_on_proposal_submit(): void
    {
        $event = $this->createEvent();
        $this->createWorkflowRun($event);

        $notificationService = app(EventNotificationService::class);
        $sentCount = $notificationService->notifyProposalSubmitted($event);

        $this->assertGreaterThanOrEqual(1, $sentCount);

        $notification = $this->organizer->fresh()->notifications()
            ->where('type', EventWorkflowNotification::class)
            ->first();

        $this->assertNotNull($notification);
        $data = $notification->data;
        $this->assertSame('event_proposal_submitted', $data['kind']);
        $this->assertSame(route('dashboard.events.checklist', $event), $data['link']);
        $this->assertSame($event->id, $data['event_id']);
        $this->assertFalse($data['needs_action']);
    }

    public function test_approval_and_advancement_notifications_emitted_on_task_approval(): void
    {
        $event = $this->createEvent();
        $run = $this->createWorkflowRun($event);
        $step = $this->createStep('adviser_endorsement', 'Adviser Endorsement', 1);
        $this->publishTemplate();
        $task = $this->createWorkflowTask($run, $step, $this->approver1, ProposalWorkflowTask::STATUS_AVAILABLE);

        app(ProposalWorkflowTaskService::class)->decide($task, $this->approver1, ProposalWorkflowTask::DECISION_APPROVED, 'Endorsement granted');

        $notifications = $this->organizer->fresh()->notifications()
            ->where('type', EventWorkflowNotification::class)
            ->get();

        $kinds = $notifications->pluck('data.kind')->all();
        $this->assertContains('workflow_task_approved', $kinds);
        $this->assertContains('workflow_advanced', $kinds);

        $approvedNotification = $notifications->firstWhere('data.kind', 'workflow_task_approved');
        $this->assertSame(route('dashboard.events.checklist', $event), $approvedNotification->data['link']);
        $this->assertStringContainsString('Adviser Endorsement', $approvedNotification->data['message']);

        $advancedNotification = $notifications->firstWhere('data.kind', 'workflow_advanced');
        $this->assertSame(route('dashboard.events.checklist', $event), $advancedNotification->data['link']);
    }

    public function test_dependent_task_unblock_notifications_emitted_to_unblocked_assignees(): void
    {
        $event = $this->createEvent();
        $run = $this->createWorkflowRun($event);

        $step1 = $this->createStep('adviser_endorsement', 'Adviser Endorsement', 1);
        $step2 = $this->createStep('osa_review', 'OSA Review', 2, 'osa', 'university');
        ProposalProcessStepDependency::create([
            'step_id' => $step2->id,
            'prerequisite_step_id' => $step1->id,
        ]);
        $this->publishTemplate();

        $task1 = $this->createWorkflowTask($run, $step1, $this->approver1, ProposalWorkflowTask::STATUS_AVAILABLE);
        $task2 = $this->createWorkflowTask($run, $step2, $this->approver2, ProposalWorkflowTask::STATUS_BLOCKED, role: 'osa', scope: 'university');

        // Before deciding task1, approver2 has no unblock notification
        $this->assertSame(0, $this->approver2->notifications()->count());

        // Approving task1 unblocks task2
        app(ProposalWorkflowTaskService::class)->decide($task1, $this->approver1, ProposalWorkflowTask::DECISION_APPROVED, 'Approved step 1');

        $task2->refresh();
        $this->assertSame(ProposalWorkflowTask::STATUS_AVAILABLE, $task2->status);

        $notification = $this->approver2->fresh()->notifications()
            ->where('type', EventWorkflowNotification::class)
            ->first();

        $this->assertNotNull($notification);
        $data = $notification->data;
        $this->assertSame('workflow_task_unblocked', $data['kind']);
        $this->assertSame(route('dashboard.workflow-tasks.show', $task2), $data['link']);
        $this->assertSame($task2->id, $data['workflow_task_id']);
        $this->assertTrue($data['needs_action']);
    }

    public function test_rejection_notification_emitted_to_organizers_with_remarks(): void
    {
        $event = $this->createEvent();
        $run = $this->createWorkflowRun($event);
        $step = $this->createStep('adviser_endorsement', 'Adviser Endorsement', 1);
        $this->publishTemplate();
        $task = $this->createWorkflowTask($run, $step, $this->approver1, ProposalWorkflowTask::STATUS_AVAILABLE);

        app(ProposalWorkflowTaskService::class)->decide(
            $task,
            $this->approver1,
            ProposalWorkflowTask::DECISION_REJECTED,
            'Event does not align with academic priorities.'
        );

        $notification = $this->organizer->fresh()->notifications()
            ->where('type', EventWorkflowNotification::class)
            ->first();

        $this->assertNotNull($notification);
        $data = $notification->data;
        $this->assertSame('workflow_task_rejected', $data['kind']);
        $this->assertSame(route('dashboard.events.checklist', $event), $data['link']);
        $this->assertStringContainsString('Event does not align with academic priorities.', $data['message']);
        $this->assertSame('error', $data['severity']);
    }

    public function test_reassignment_notifications_emitted_to_new_and_former_assignees(): void
    {
        $event = $this->createEvent();
        $run = $this->createWorkflowRun($event);
        $step = $this->createStep('osa_review', 'OSA Review', 1, 'osa', 'university');
        $this->publishTemplate();
        $task = $this->createWorkflowTask($run, $step, $this->approver2, ProposalWorkflowTask::STATUS_AVAILABLE, role: 'osa', scope: 'university');

        $replacementUser = User::factory()->create([
            'name' => 'Replacement OSA Officer',
            'email' => 'replacement.osa@usm.edu.ph',
        ]);
        $replacementUser->assignRole('Super Admin (OSA)');

        AdminAssignment::create([
            'user_id' => $replacementUser->id,
            'role_name' => 'Super Admin (OSA)',
            'position_title' => 'OSA Officer',
            'school_year_id' => $this->schoolYear->id,
            'academic_year' => $this->schoolYear->label,
            'status' => AdminAssignment::STATUS_ACTIVE,
            'is_primary_admin' => false,
            'approved_at' => now(),
        ]);

        app(ProposalWorkflowTaskService::class)->reassign(
            $task,
            $replacementUser,
            $this->approver2,
            'OSA Director on institutional travel'
        );

        // Replacement user receives assignment with show link
        $newNotification = $replacementUser->fresh()->notifications()
            ->where('type', EventWorkflowNotification::class)
            ->first();

        $this->assertNotNull($newNotification);
        $newData = $newNotification->data;
        $this->assertSame('workflow_task_reassigned', $newData['kind']);
        $this->assertSame(route('dashboard.workflow-tasks.show', $task), $newData['link']);
        $this->assertStringContainsString('OSA Director on institutional travel', $newData['message']);
        $this->assertTrue($newData['needs_action']);

        // Former assignee receives unassignment notice with index link
        $formerNotification = $this->approver2->fresh()->notifications()
            ->where('type', EventWorkflowNotification::class)
            ->first();

        $this->assertNotNull($formerNotification);
        $formerData = $formerNotification->data;
        $this->assertSame('workflow_task_unassigned', $formerData['kind']);
        $this->assertSame(route('dashboard.workflow-tasks.index'), $formerData['link']);
        $this->assertStringContainsString('OSA Director on institutional travel', $formerData['message']);
        $this->assertFalse($formerData['needs_action']);
    }

    public function test_revision_request_notification_emitted_to_organizers(): void
    {
        $event = $this->createEvent();
        $run = $this->createWorkflowRun($event);
        $step = $this->createStep('adviser_endorsement', 'Adviser Endorsement', 1);
        $this->publishTemplate();
        $task = $this->createWorkflowTask($run, $step, $this->approver1, ProposalWorkflowTask::STATUS_AVAILABLE);

        app(ProposalWorkflowTaskService::class)->decide(
            $task,
            $this->approver1,
            ProposalWorkflowTask::DECISION_REVISION_REQUESTED,
            'Please update the venue risk assessment section.',
            [
                'type' => 'packet_section',
                'key' => 'venue',
            ]
        );

        $notification = $this->organizer->fresh()->notifications()
            ->where('type', EventWorkflowNotification::class)
            ->first();

        $this->assertNotNull($notification);
        $data = $notification->data;
        $this->assertSame('workflow_task_revision_requested', $data['kind']);
        $this->assertSame(route('dashboard.events.checklist', $event), $data['link']);
        $this->assertStringContainsString('Please update the venue risk assessment section.', $data['message']);
        $this->assertTrue($data['needs_action']);
        $this->assertSame('warning', $data['severity']);
    }

    public function test_resubmission_notification_emitted_to_assigned_reviewer_on_resubmit(): void
    {
        $event = $this->createEvent(Event::STATUS_REVISION_REQUESTED);
        $run = $this->createWorkflowRun($event, ProposalWorkflowRun::STATUS_REVISION_REQUESTED);
        $step = $this->createStep('adviser_endorsement', 'Adviser Endorsement', 1);
        $this->publishTemplate();
        $task = $this->createWorkflowTask(
            $run,
            $step,
            $this->approver1,
            ProposalWorkflowTask::STATUS_REVISION_REQUESTED,
            ProposalWorkflowTask::DECISION_REVISION_REQUESTED
        );

        ApprovalTrail::create([
            'proposal_id' => $event->id,
            'workflow_run_id' => $run->id,
            'workflow_task_id' => $task->id,
            'actor_id' => $this->approver1->id,
            'decision' => ProposalWorkflowTask::DECISION_REVISION_REQUESTED,
            'remarks' => 'Fix budget items',
            'reference_id' => 'AT-notification-test',
            'previous_status' => ProposalWorkflowTask::STATUS_AVAILABLE,
            'resulting_status' => ProposalWorkflowTask::STATUS_REVISION_REQUESTED,
            'assigned_role' => 'adviser',
            'assigned_scope' => 'organization',
            'decided_at' => now()->subHour(),
        ]);

        $notificationService = app(EventNotificationService::class);
        $sentCount = $notificationService->notifyWorkflowTaskResubmitted($task);

        $this->assertSame(1, $sentCount);

        $notification = $this->approver1->fresh()->notifications()
            ->where('type', EventWorkflowNotification::class)
            ->first();

        $this->assertNotNull($notification);
        $data = $notification->data;
        $this->assertSame('workflow_task_resubmitted', $data['kind']);
        $this->assertSame(route('dashboard.workflow-tasks.show', $task), $data['link']);
        $this->assertSame($task->id, $data['workflow_task_id']);
        $this->assertTrue($data['needs_action']);
    }

    public function test_final_permit_approval_notification_emitted_with_permit_link(): void
    {
        $event = $this->createEvent();
        $this->createWorkflowRun($event);

        $notificationService = app(EventNotificationService::class);
        $sentCount = $notificationService->notifyFinalPermitApproved($event, 1);

        $this->assertGreaterThanOrEqual(1, $sentCount);

        $notification = $this->organizer->fresh()->notifications()
            ->where('type', EventWorkflowNotification::class)
            ->first();

        $this->assertNotNull($notification);
        $data = $notification->data;
        $this->assertSame('final_permit_approved', $data['kind']);
        $this->assertSame(route('dashboard.events.permit-preview', $event), $data['link']);
        $this->assertSame('success', $data['severity']);
        $this->assertFalse($data['needs_action']);
        $this->assertSame("final_permit_approved:{$event->id}:1", $data['notification_key']);
    }

    public function test_participant_scope_review_notification_resolves_to_participating_workspace(): void
    {
        $event = $this->createEvent();
        $scope = EventParticipationScope::create([
            'event_id' => $event->id,
            'organization_id' => $this->organization->id,
            'participation_role' => EventParticipationScope::ROLE_PARTICIPANT,
            'status' => EventParticipationScope::STATUS_ACTIVE,
            'ppa_status' => EventParticipationScope::PPA_APPROVED,
            'reviewed_at' => now(),
        ]);

        $notificationService = app(EventNotificationService::class);
        $sentCount = $notificationService->notifyParticipantScopeReviewed($scope, EventParticipationScope::PPA_APPROVED);

        $this->assertGreaterThanOrEqual(1, $sentCount);

        $notification = $this->organizer->fresh()->notifications()
            ->where('type', EventWorkflowNotification::class)
            ->first();

        $this->assertNotNull($notification);
        $data = $notification->data;
        $this->assertSame(route('dashboard.events', ['workspace' => 'participating']), $data['link']);
    }

    public function test_cross_tenant_isolation_prevents_peer_organizations_from_receiving_notifications(): void
    {
        // Create Peer Organization and its separate officer
        $peerOrg = Organization::create([
            'college_id' => $this->college->id,
            'department_id' => $this->department->id,
            'name' => 'Peer Society',
            'acronym' => 'PS',
            'type' => 'society',
            'status' => 'active',
        ]);

        $peerOfficer = User::factory()->create([
            'name' => 'Peer Officer',
            'email' => 'peer.officer@usm.edu.ph',
            'college_id' => $this->college->id,
        ]);
        $peerOfficer->assignRole('Society Admin');

        app(AdminAssignmentService::class)->createAssignment(
            user: $peerOfficer,
            roleName: 'Society Admin',
            organization: $peerOrg,
            college: $this->college,
            academicYear: $this->schoolYear->label,
            termStart: today()->subMonth()->toDateString(),
            termEnd: today()->addYear()->toDateString(),
            positionTitle: 'Peer President',
            approvedBy: $peerOfficer,
            isPrimaryAdmin: true,
        );

        $event = $this->createEvent();
        $run = $this->createWorkflowRun($event);
        $step = $this->createStep('adviser_endorsement', 'Adviser Endorsement', 1);
        $this->publishTemplate();
        $task = $this->createWorkflowTask($run, $step, $this->approver1, ProposalWorkflowTask::STATUS_AVAILABLE);

        // Advance and approve task for CSG
        app(ProposalWorkflowTaskService::class)->decide($task, $this->approver1, ProposalWorkflowTask::DECISION_APPROVED, 'Endorsed');

        // Final permit approval notification
        app(EventNotificationService::class)->notifyFinalPermitApproved($event, 1);

        // Ensure organizer of CSG received notifications
        $this->assertGreaterThan(0, $this->organizer->fresh()->notifications()->count());

        // Ensure peer officer received ZERO notifications for CSG event
        $peerNotifications = $peerOfficer->fresh()->notifications()
            ->where('type', EventWorkflowNotification::class)
            ->get();

        $this->assertCount(0, $peerNotifications);
    }

    public function test_duplicate_suppression_prevents_duplicate_notifications_for_same_event(): void
    {
        $event = $this->createEvent();
        $run = $this->createWorkflowRun($event);
        $step = $this->createStep('adviser_endorsement', 'Adviser Endorsement', 1);
        $this->publishTemplate();
        $task = $this->createWorkflowTask($run, $step, $this->approver1, ProposalWorkflowTask::STATUS_AVAILABLE);

        $notificationService = app(EventNotificationService::class);

        // First dispatch should succeed
        $firstResult = $notificationService->notifyWorkflowTaskAssigned($task);
        $this->assertSame(1, $firstResult);
        $this->assertSame(1, $this->approver1->fresh()->notifications()->count());

        // Immediate second dispatch with same key should be suppressed
        $secondResult = $notificationService->notifyWorkflowTaskAssigned($task);
        $this->assertSame(0, $secondResult);
        $this->assertSame(1, $this->approver1->fresh()->notifications()->count());
    }

    public function test_transaction_rollback_prevents_notifications_from_being_sent(): void
    {
        $event = $this->createEvent();
        $run = $this->createWorkflowRun($event);
        $step = $this->createStep('adviser_endorsement', 'Adviser Endorsement', 1);
        $this->publishTemplate();
        $task = $this->createWorkflowTask($run, $step, $this->approver1, ProposalWorkflowTask::STATUS_AVAILABLE);

        $exceptionThrown = false;
        try {
            DB::transaction(function () use ($task): void {
                app(ProposalWorkflowTaskService::class)->decide(
                    $task,
                    $this->approver1,
                    ProposalWorkflowTask::DECISION_APPROVED,
                    'Tentative approval'
                );

                // Simulate failure before commit
                throw new Exception('Simulated database failure during transaction');
            });
        } catch (Exception $e) {
            $exceptionThrown = true;
        }

        $this->assertTrue($exceptionThrown);

        // Confirm task was not committed as approved
        $this->assertSame(ProposalWorkflowTask::STATUS_AVAILABLE, $task->fresh()->status);

        // Confirm no notifications were dispatched to organizer or approvers
        $this->assertSame(0, $this->organizer->fresh()->notifications()->count());
        $this->assertSame(0, $this->approver1->fresh()->notifications()->count());
    }

    private function createEvent(string $status = Event::STATUS_SUBMITTED): Event
    {
        $event = Event::create([
            'organization_id' => $this->organization->id,
            'school_year_id' => $this->schoolYear->id,
            'department_id' => $this->department->id,
            'title' => 'Guild Hackathon 2026',
            'proposal_category' => 'activity',
            'status' => $status,
            'lifecycle_phase' => 'scheduled',
            'event_domain' => 'student_affairs',
            'start_date' => today()->addMonth(),
            'end_date' => today()->addMonth(),
            'location_type' => 'on-campus',
            'college_name_snapshot' => $this->college->name,
            'department_name_snapshot' => $this->department->name,
            'organization_name_snapshot' => $this->organization->name,
            'proponent_name_snapshot' => $this->organizer->name,
            'proponent_user_id' => $this->organizer->id,
            'org_head_user_id' => $this->organizer->id,
            'governance_snapshots' => [
                'organization_name_snapshot' => $this->organization->name,
                'metadata' => [
                    'school_year_label' => $this->schoolYear->label,
                    'college_code' => $this->college->code,
                    'department_code' => $this->department->code,
                    'organization_acronym' => $this->organization->acronym,
                    'proponent_email' => $this->organizer->email,
                ],
            ],
        ]);

        CanonicalPpaPacket::create([
            'event_id' => $event->id,
            'title' => $event->title,
            'proponent_name' => $this->organizer->name,
            'proponent_position' => 'President',
            'proponent_organization' => $this->organization->name,
            'proponent_email' => $this->organizer->email,
            'proponent_contact_number' => '09171234567',
            'implementing_office' => $this->organization->name,
            'budget_cost' => 5000,
            'start_date' => $event->start_date,
            'end_date' => $event->end_date,
            'location_type' => 'on-campus',
            'rationale' => 'Promote computing excellence.',
            'general_objective' => 'Engage students in software dev.',
            'specific_objectives' => ['Learn team building', 'Build solutions'],
            'methodology' => '24-hour sprint',
            'program_activities' => [[
                'date' => today()->addMonth()->toDateString(),
                'time' => '08:00 - 17:00',
                'activity' => 'Hackathon Day 1',
                'person_in_charge' => $this->organizer->name,
            ]],
            'expected_output' => 'Completed software projects',
            'beneficiaries' => 'CS Students',
            'budget_breakdown' => [['item' => 'Food', 'amount' => '5000.00']],
        ]);

        EventParticipationScope::create([
            'event_id' => $event->id,
            'organization_id' => $this->organization->id,
            'participation_role' => EventParticipationScope::ROLE_ORGANIZER,
            'status' => EventParticipationScope::STATUS_ACTIVE,
            'ppa_status' => EventParticipationScope::PPA_SUBMITTED,
        ]);

        return $event;
    }

    private function createWorkflowRun(Event $event, string $status = ProposalWorkflowRun::STATUS_IN_PROGRESS): ProposalWorkflowRun
    {
        return ProposalWorkflowRun::create([
            'event_id' => $event->id,
            'template_version_id' => $this->templateVersion->id,
            'status' => $status,
            'started_by' => $this->organizer->id,
            'started_at' => now(),
            'submitted_at' => now(),
            'completed_at' => $status === ProposalWorkflowRun::STATUS_APPROVED ? now() : null,
        ]);
    }

    private function createStep(string $key, string $title, int $order, string $role = 'adviser', string $scope = 'organization'): ProposalProcessStep
    {
        return ProposalProcessStep::create([
            'template_version_id' => $this->templateVersion->id,
            'step_key' => $key,
            'title' => $title,
            'step_type' => ProposalProcessStep::TYPE_APPROVAL,
            'phase' => 'approval',
            'display_order' => $order,
            'is_required' => true,
            'condition_config' => [
                'assigned_role' => $role,
                'assigned_scope' => $scope,
            ],
        ]);
    }

    private function publishTemplate(): void
    {
        if ($this->templateVersion->status !== ProposalProcessTemplateVersion::STATUS_PUBLISHED) {
            $this->templateVersion->update([
                'status' => ProposalProcessTemplateVersion::STATUS_PUBLISHED,
                'published_at' => now(),
            ]);
        }
    }

    private function createWorkflowTask(
        ProposalWorkflowRun $run,
        ProposalProcessStep $step,
        User $assignee,
        string $status,
        ?string $decision = null,
        string $role = 'adviser',
        string $scope = 'organization'
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
            'assigned_role' => $role,
            'assigned_scope' => $scope,
            'available_at' => $status === ProposalWorkflowTask::STATUS_AVAILABLE ? now() : null,
            'completed_at' => in_array($status, [ProposalWorkflowTask::STATUS_APPROVED, ProposalWorkflowTask::STATUS_REJECTED], true) ? now() : null,
            'decision' => $decision,
            'decided_by' => $decision ? $assignee->id : null,
            'decided_at' => $decision ? now() : null,
            'metadata' => [
                'assignment' => [
                    'role_key' => $role,
                    'scope_key' => $scope,
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
