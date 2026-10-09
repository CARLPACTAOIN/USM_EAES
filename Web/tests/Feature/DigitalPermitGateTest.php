<?php

namespace Tests\Feature;

use App\Models\AdminAssignment;
use App\Models\ApprovalTrail;
use App\Models\CanonicalPpaPacket;
use App\Models\College;
use App\Models\Event;
use App\Models\EventDay;
use App\Models\Organization;
use App\Models\ProposalProcessStep;
use App\Models\ProposalProcessTemplate;
use App\Models\ProposalProcessTemplateVersion;
use App\Models\ProposalWorkflowRun;
use App\Models\ProposalWorkflowTask;
use App\Models\SchoolYear;
use App\Models\University;
use App\Models\User;
use App\Models\Venue;
use App\Services\AdminAssignmentService;
use App\Services\EventPermitService;
use App\Services\ProposalWorkflowTaskService;
use App\Support\EvaluationQuestions;
use App\Support\EventEvaluationForm;
use Database\Seeders\RolePermissionSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DigitalPermitGateTest extends TestCase
{
    use RefreshDatabase;

    private SchoolYear $schoolYear;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Storage::fake('local');

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
            'name' => 'College of Digital Permits',
            'code' => 'CDP',
        ]);

        $this->organization = Organization::create([
            'college_id' => $college->id,
            'name' => 'Digital Permit Society',
            'acronym' => 'DPS',
            'type' => 'society',
            'status' => 'active',
        ]);
    }

    public function test_digital_permit_approval_is_locked_until_required_permit_gates_are_satisfied(): void
    {
        $osa = $this->osaUser();
        $event = $this->eventReadyForApproval($osa);
        $run = $this->workflowRun($event, $osa);
        $this->workflowTask($run, $this->step($run, 'security_clearance', 'Security Clearance', 1, isGate: true), $osa, ProposalWorkflowTask::STATUS_AVAILABLE);
        $osaTask = $this->workflowTask($run, $this->step($run, 'osa_final_approval', 'OSA Final Permit Approval', 2, isGate: true), $osa, ProposalWorkflowTask::STATUS_BLOCKED);

        $issuedEvent = app(EventPermitService::class)
            ->issueIfCompletedOrganizerOsaPermitGateDecision($osaTask, $osa);

        $this->assertNull($issuedEvent);
        $this->assertSame(Event::STATUS_UNDER_REVIEW, $event->fresh()->status);
        $this->assertDatabaseCount('event_permit_versions', 0);
    }

    public function test_digital_permit_workflow_gate_blocks_issuance_until_gate_task_decision_completed(): void
    {
        $osa = $this->osaUser();
        $event = $this->eventReadyForApproval($osa);
        $run = $this->workflowRun($event, $osa);
        $osaTask = $this->workflowTask($run, $this->step($run, 'osa_final_approval', 'OSA Final Permit Approval', 1, isGate: true), $osa, ProposalWorkflowTask::STATUS_AVAILABLE);

        $issuedEvent = app(EventPermitService::class)
            ->issueIfCompletedOrganizerOsaPermitGateDecision($osaTask, $osa);

        $this->assertNull($issuedEvent);
        $this->assertDatabaseCount('event_permit_versions', 0);
        $this->assertSame(Event::STATUS_UNDER_REVIEW, $event->fresh()->status);
    }

    public function test_final_task_decision_rolls_back_when_locked_evaluation_form_is_missing(): void
    {
        $osa = $this->osaUser();
        $event = $this->eventReadyForApproval($osa);
        $event->update(['evaluation_form_version_id' => null]);
        $run = $this->workflowRun($event, $osa);
        $task = $this->workflowTask(
            $run,
            $this->step($run, 'osa_final_approval', 'OSA Final Permit Approval', 1, isGate: true),
            $osa,
            ProposalWorkflowTask::STATUS_AVAILABLE
        );

        try {
            app(ProposalWorkflowTaskService::class)->decide(
                $task,
                $osa,
                ProposalWorkflowTask::DECISION_APPROVED
            );
            $this->fail('The task decision should roll back when the permit cannot be issued.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('locked evaluation form', $exception->getMessage());
        }

        $this->assertSame(ProposalWorkflowTask::STATUS_AVAILABLE, $task->fresh()->status);
        $this->assertSame(ProposalWorkflowRun::STATUS_IN_PROGRESS, $run->fresh()->status);
        $this->assertSame(Event::STATUS_UNDER_REVIEW, $event->fresh()->status);
        $this->assertDatabaseCount('approval_trails', 0);
        $this->assertDatabaseCount('event_permit_versions', 0);
    }

    public function test_workflow_task_decisions_record_approval_trails_without_legacy_compatibility_projections(): void
    {
        $approver = $this->osaUser();
        $event = $this->eventReadyForApproval($approver);
        $run = $this->workflowRun($event, $approver);
        $organizationHeadTask = $this->workflowTask(
            $run,
            $this->step($run, 'organization_head_endorsement', 'Organization Head Endorsement', 1, isGate: false, role: 'organization_head'),
            $approver,
            ProposalWorkflowTask::STATUS_AVAILABLE
        );
        $adviserTask = $this->workflowTask(
            $run,
            $this->step($run, 'adviser_endorsement', 'Adviser Endorsement', 2, isGate: false, role: 'adviser'),
            $approver,
            ProposalWorkflowTask::STATUS_AVAILABLE
        );

        app(ProposalWorkflowTaskService::class)->decide(
            $organizationHeadTask,
            $approver,
            ProposalWorkflowTask::DECISION_APPROVED
        );

        $this->assertDatabaseHas('approval_trails', [
            'workflow_task_id' => $organizationHeadTask->id,
            'actor_id' => $approver->id,
            'decision' => ProposalWorkflowTask::DECISION_APPROVED,
        ]);
        $this->assertSame(ProposalWorkflowTask::STATUS_APPROVED, $organizationHeadTask->fresh()->status);
        $this->assertSame(ProposalWorkflowRun::STATUS_IN_PROGRESS, $run->fresh()->status);

        app(ProposalWorkflowTaskService::class)->decide(
            $adviserTask->fresh(),
            $approver,
            ProposalWorkflowTask::DECISION_APPROVED
        );

        $this->assertDatabaseHas('approval_trails', [
            'workflow_task_id' => $adviserTask->id,
            'actor_id' => $approver->id,
            'decision' => ProposalWorkflowTask::DECISION_APPROVED,
        ]);
        $this->assertSame(ProposalWorkflowTask::STATUS_APPROVED, $adviserTask->fresh()->status);
        $this->assertSame(ProposalWorkflowRun::STATUS_APPROVED, $run->fresh()->status);
    }

    public function test_final_approval_creates_immutable_version_snapshots_and_copy_owners(): void
    {
        $osa = $this->osaUser();
        $organizer = $this->organizerUser();
        $event = $this->eventReadyForApproval($osa, ['organization_name_snapshot' => 'Digital Permit Society']);
        $run = $this->workflowRun($event, $osa);
        $securityStep = $this->step($run, 'security_clearance', 'Security Clearance', 1, isGate: true, role: 'chief_security');
        $osaStep = $this->step($run, 'osa_final_approval', 'OSA Final Permit Approval', 2, isGate: true);
        $securityTask = $this->workflowTask($run, $securityStep, $osa, ProposalWorkflowTask::STATUS_APPROVED, ProposalWorkflowTask::DECISION_APPROVED);
        $osaTask = $this->workflowTask($run, $osaStep, $osa, ProposalWorkflowTask::STATUS_AVAILABLE);
        $securityTrail = $this->approvalTrail($event, $run, $securityTask, $osa, 'AT-SECURITY-001');

        app(ProposalWorkflowTaskService::class)->decide(
            $osaTask,
            $osa,
            ProposalWorkflowTask::DECISION_APPROVED,
            'Final packet and permit approved.'
        );

        $approved = $event->fresh();
        $osaTrail = ApprovalTrail::where('workflow_task_id', $osaTask->id)->firstOrFail();
        $version = $approved->currentPermitVersion()->firstOrFail();

        $this->assertSame(1, $approved->permit_version);
        $this->assertSame($run->id, $version->workflow_run_id);
        $this->assertSame($osaTrail->reference_id, $version->verification_reference);
        $this->assertSame('Digital Permit Packet', data_get($version->canonical_packet_snapshot, 'title'));
        $this->assertSame($event->venue_confirmation_document_path, data_get($version->evidence_snapshot, 'venue_confirmation_document_path'));
        $this->assertSame([$securityTrail->id, $osaTrail->id], collect($version->approval_metadata_snapshot)->pluck('approval_trail_id')->all());
        $this->assertDatabaseHas('permit_copy_owners', ['event_permit_version_id' => $version->id, 'owner_type' => 'osa', 'owner_name' => 'Office of Student Affairs']);
        $this->assertDatabaseHas('permit_copy_owners', ['event_permit_version_id' => $version->id, 'owner_type' => 'security', 'owner_name' => 'Office of Security']);
        $this->assertDatabaseHas('permit_copy_owners', ['event_permit_version_id' => $version->id, 'owner_type' => 'organizer', 'owner_id' => $this->organization->id, 'owner_name' => 'Digital Permit Society']);

        $updateWasBlocked = false;
        try {
            $version->update(['verification_reference' => 'CHANGED']);
        } catch (DomainException) {
            $updateWasBlocked = true;
        }

        $databaseUpdateWasBlocked = false;
        try {
            DB::table('event_permit_versions')->where('id', $version->id)->update(['verification_reference' => 'CHANGED']);
        } catch (\Throwable) {
            $databaseUpdateWasBlocked = true;
        }

        $this->assertTrue($updateWasBlocked);
        $this->assertTrue($databaseUpdateWasBlocked);
        $this->assertSame($osaTrail->reference_id, $version->fresh()->verification_reference);

        $response = $this->actingAs($organizer)->get(route('dashboard.events.permit-history.certificate', [$event, $version]));
        $response->assertOk();
        $this->assertStringContainsString('application/pdf', $response->headers->get('content-type'));
    }

    public function test_permit_certificate_download_is_locked_until_active_version_exists(): void
    {
        $organizer = $this->organizerUser();
        $event = $this->eventReadyForApproval($organizer);

        $this->actingAs($organizer)
            ->get(route('dashboard.events.permit-certificate', $event))
            ->assertNotFound();
    }

    private function osaUser(): User
    {
        $user = User::factory()->create(['email' => 'osa.digital.permit.'.uniqid().'@usm.edu.ph']);
        $user->assignRole('Super Admin (OSA)');

        return $user;
    }

    private function organizerUser(): User
    {
        $user = User::factory()->create(['email' => 'organizer.digital.permit.'.uniqid().'@usm.edu.ph']);
        app(AdminAssignmentService::class)->createAssignment(
            user: $user,
            roleName: 'Society Admin',
            organization: $this->organization,
            college: $this->organization->college,
            academicYear: $this->schoolYear->label,
            termStart: today()->subMonth()->toDateString(),
            termEnd: today()->addYear()->toDateString(),
            positionTitle: 'Society President',
            approvedBy: $user,
            isPrimaryAdmin: ! AdminAssignment::where('role_name', 'Society Admin')->where('status', AdminAssignment::STATUS_ACTIVE)->exists(),
        );

        return $user->fresh();
    }

    /** @param array<string, mixed> $overrides */
    private function eventReadyForApproval(User $approver, array $overrides = []): Event
    {
        $venue = Venue::factory()->create([
            'name' => 'USM Auditorium',
            'campus_location' => 'Main Campus',
            'managing_office' => 'Campus Facilities Office',
        ]);
        Storage::put('proposal-documents/digital-permit.pdf', 'proposal packet');
        Storage::put('venue-confirmations/digital-permit.pdf', 'venue evidence');

        $event = Event::create(array_merge([
            'organization_id' => $this->organization->id,
            'school_year_id' => $this->schoolYear->id,
            'title' => 'Digital Permit Packet',
            'proposal_category' => 'activity',
            'status' => Event::STATUS_UNDER_REVIEW,
            'lifecycle_phase' => 'scheduled',
            'event_domain' => 'student_affairs',
            'start_date' => today()->addMonth(),
            'end_date' => today()->addMonth(),
            'location_type' => 'on-campus',
            'venue_id' => $venue->id,
            'location_details' => $venue->displayName(),
            'venue_confirmation_office' => $venue->managing_office,
            'venue_confirmation_date' => today(),
            'venue_confirmation_reference_number' => 'VC-DP-001',
            'venue_confirmation_document_path' => 'venue-confirmations/digital-permit.pdf',
            'venue_confirmation_document_original_name' => 'venue-confirmation.pdf',
            'proposal_document_path' => 'proposal-documents/digital-permit.pdf',
            'proposal_document_original_name' => 'digital-permit.pdf',

            'organization_name_snapshot' => $this->organization->name,
            'proponent_name_snapshot' => 'Permit Proponent',
        ], $overrides));

        EventDay::create([
            'event_id' => $event->id,
            'day_number' => 1,
            'date' => today()->addMonth(),
            'start_time' => '08:00',
            'end_time' => '12:00',
        ]);

        CanonicalPpaPacket::factory()->for($event)->create([
            'title' => $event->title,
            'proponent_name' => 'Permit Proponent',
            'proponent_position' => 'President',
            'proponent_organization' => $this->organization->name,
            'proponent_email' => 'permit.proponent@usm.edu.ph',
            'proponent_contact_number' => '09170000000',
            'implementing_office' => $this->organization->name,
            'budget_cost' => 1500,
            'start_date' => $event->start_date,
            'end_date' => $event->end_date,
            'location_type' => $event->location_type,
            'venue_id' => $event->venue_id,
            'location_details' => $event->location_details,
            'venue_confirmation_office' => $event->venue_confirmation_office,
            'venue_confirmation_date' => $event->venue_confirmation_date,
            'venue_confirmation_reference_number' => $event->venue_confirmation_reference_number,
            'venue_confirmation_document_path' => $event->venue_confirmation_document_path,
            'venue_confirmation_document_original_name' => $event->venue_confirmation_document_original_name,
            'rationale' => 'Digital permit approval requires complete metadata.',
            'general_objective' => 'Create a verifiable digital permit.',
            'specific_objectives' => ['Complete permit workflow'],
            'methodology' => 'Route and approve the workflow.',
            'program_activities' => [[
                'date' => today()->addMonth()->toDateString(),
                'time' => '08:00 - 12:00',
                'activity' => 'Permit activity',
                'person_in_charge' => 'Permit Proponent',
            ]],
            'expected_output' => 'Official digital permit.',
            'beneficiaries' => 'Digital permit participants',
            'budget_breakdown' => [['item' => 'Materials', 'amount' => '1500.00']],
        ]);
        EventEvaluationForm::createForEvent($event, $approver, EvaluationQuestions::defaultSchema(), 'Evaluation');

        return $event;
    }

    private function workflowRun(Event $event, User $actor, string $status = ProposalWorkflowRun::STATUS_IN_PROGRESS): ProposalWorkflowRun
    {
        $template = ProposalProcessTemplate::create([
            'name' => 'Digital Permit Workflow '.uniqid(),
            'status' => ProposalProcessTemplate::STATUS_ACTIVE,
        ]);
        $version = ProposalProcessTemplateVersion::create([
            'template_id' => $template->id,
            'version_number' => 1,
            'status' => ProposalProcessTemplateVersion::STATUS_DRAFT,
        ]);

        return ProposalWorkflowRun::create([
            'event_id' => $event->id,
            'template_version_id' => $version->id,
            'status' => $status,
            'started_by' => $actor->id,
            'started_at' => now(),
            'submitted_at' => now(),
            'completed_at' => $status === ProposalWorkflowRun::STATUS_APPROVED ? now() : null,
        ]);
    }

    private function step(ProposalWorkflowRun $run, string $key, string $title, int $order, bool $isGate, string $role = 'osa'): ProposalProcessStep
    {
        return ProposalProcessStep::create([
            'template_version_id' => $run->template_version_id,
            'step_key' => $key,
            'title' => $title,
            'step_type' => ProposalProcessStep::TYPE_APPROVAL,
            'phase' => 'permit',
            'display_order' => $order,
            'is_required' => true,
            'is_gate' => $isGate,
            'condition_config' => [
                'assigned_role' => $role,
                'assigned_scope' => 'university',
            ],
        ]);
    }

    private function workflowTask(
        ProposalWorkflowRun $run,
        ProposalProcessStep $step,
        User $assignee,
        string $status,
        ?string $decision = null
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
            'assigned_role' => $step->condition_config['assigned_role'],
            'assigned_scope' => 'university',
            'available_at' => now(),
            'completed_at' => in_array($status, [ProposalWorkflowTask::STATUS_APPROVED, ProposalWorkflowTask::STATUS_NOT_REQUIRED], true) ? now() : null,
            'decision' => $decision,
            'decided_by' => $decision ? $assignee->id : null,
            'decided_at' => $decision ? now() : null,
            'metadata' => [
                'assignment' => [
                    'role_key' => $step->condition_config['assigned_role'],
                    'scope_key' => 'university',
                    'assigned_user_snapshot' => [
                        'id' => $assignee->id,
                        'name' => $assignee->name,
                        'email' => $assignee->email,
                    ],
                ],
            ],
        ]);
    }

    private function approvalTrail(
        Event $event,
        ProposalWorkflowRun $run,
        ProposalWorkflowTask $task,
        User $actor,
        string $referenceId
    ): ApprovalTrail {
        return ApprovalTrail::create([
            'proposal_id' => $event->id,
            'workflow_run_id' => $run->id,
            'workflow_task_id' => $task->id,
            'actor_id' => $actor->id,
            'decision' => ProposalWorkflowTask::DECISION_APPROVED,
            'remarks' => 'Approved for permit issuance.',
            'reference_id' => $referenceId,
            'previous_status' => ProposalWorkflowTask::STATUS_AVAILABLE,
            'resulting_status' => ProposalWorkflowTask::STATUS_APPROVED,
            'assigned_role' => $task->assigned_role,
            'assigned_scope' => $task->assigned_scope,
            'decided_at' => now(),
            'metadata' => [
                'actor' => [
                    'id' => $actor->id,
                    'name' => $actor->name,
                    'email' => $actor->email,
                ],
                'assignment' => $task->metadata['assignment'] ?? [],
            ],
        ]);
    }
}
