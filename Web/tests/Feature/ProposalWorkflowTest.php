<?php

namespace Tests\Feature;

use App\Models\AdminAssignment;
use App\Models\CanonicalPpaPacket;
use App\Models\College;
use App\Models\Department;
use App\Models\Event;
use App\Models\EventDay;
use App\Models\EventParticipationScope;
use App\Models\Organization;
use App\Models\OrganizationOfficer;
use App\Models\OrganizationOfficerRoster;
use App\Models\ProposalProcessStep;
use App\Models\ProposalProcessStepDependency;
use App\Models\ProposalProcessTemplate;
use App\Models\ProposalProcessTemplateVersion;
use App\Models\ProposalTemplateApplicabilityRule;
use App\Models\ProposalWorkflowRun;
use App\Models\ProposalWorkflowTask;
use App\Models\SchoolYear;
use App\Models\User;
use App\Models\Venue;
use App\Services\AdminAssignmentService;
use App\Services\EventPermitService;
use App\Services\ProposalWorkflowService;
use Database\Seeders\RolePermissionSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProposalWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Storage::fake('local');
    }

    public function test_first_submission_atomically_binds_to_seeded_workflow_run_and_creates_tasks(): void
    {
        [$organization, $organizer] = $this->setupOrganizationWithOrganizer();
        $event = $this->createSubmittableEvent($organization, $organizer);

        $this->assertSame(Event::STATUS_DRAFT, $event->status);
        $this->assertDatabaseCount('proposal_workflow_runs', 0);
        $this->assertDatabaseCount('proposal_workflow_tasks', 0);

        $submittedEvent = app(EventPermitService::class)->submit($event, $organizer);

        // Assert event status vs workflow run status separation
        $this->assertSame(Event::STATUS_SUBMITTED, $submittedEvent->status);

        $run = ProposalWorkflowRun::where('event_id', $event->id)->first();
        $this->assertNotNull($run);
        $this->assertSame(ProposalWorkflowRun::STATUS_IN_PROGRESS, $run->status);
        $this->assertSame($organizer->id, $run->started_by);

        // Assert tasks were created with expected prerequisite states
        $tasks = $run->tasks()->orderBy('display_order')->get();
        $this->assertCount(5, $tasks);

        // Task 1: Adviser Endorsement (no prereqs -> available)
        $adviserTask = $tasks->firstWhere('step_key', 'adviser_endorsement');
        $this->assertNotNull($adviserTask);
        $this->assertSame(ProposalWorkflowTask::STATUS_AVAILABLE, $adviserTask->status);
        $this->assertSame(1, $adviserTask->display_order);
        $this->assertSame(ProposalProcessStep::TYPE_ENDORSEMENT, $adviserTask->step_type);
        $this->assertNotNull($adviserTask->available_at);

        // Task 2: Department Chair Endorsement (blocked by adviser)
        $chairTask = $tasks->firstWhere('step_key', 'dept_chair_endorsement');
        $this->assertNotNull($chairTask);
        $this->assertSame(ProposalWorkflowTask::STATUS_BLOCKED, $chairTask->status);
        $this->assertSame(2, $chairTask->display_order);
        $this->assertSame(ProposalProcessStep::TYPE_ENDORSEMENT, $chairTask->step_type);
        $this->assertNull($chairTask->available_at);

        // Task 3: College Dean Endorsement (blocked by chair)
        $deanTask = $tasks->firstWhere('step_key', 'college_dean_endorsement');
        $this->assertNotNull($deanTask);
        $this->assertSame(ProposalWorkflowTask::STATUS_BLOCKED, $deanTask->status);
        $this->assertSame(3, $deanTask->display_order);
        $this->assertSame(ProposalProcessStep::TYPE_ENDORSEMENT, $deanTask->step_type);
        $this->assertNull($deanTask->available_at);

        // Task 4: Security Clearance (blocked by adviser)
        $securityTask = $tasks->firstWhere('step_key', 'security_clearance');
        $this->assertNotNull($securityTask);
        $this->assertSame(ProposalWorkflowTask::STATUS_BLOCKED, $securityTask->status);
        $this->assertSame(4, $securityTask->display_order);
        $this->assertSame(ProposalProcessStep::TYPE_CLEARANCE, $securityTask->step_type);
        $this->assertNull($securityTask->available_at);

        // Task 5: OSA Review & Approval (blocked by dean & venue clearance)
        $osaTask = $tasks->firstWhere('step_key', 'osa_review_and_approval');
        $this->assertNotNull($osaTask);
        $this->assertSame(ProposalWorkflowTask::STATUS_BLOCKED, $osaTask->status);
        $this->assertSame(5, $osaTask->display_order);
        $this->assertSame(ProposalProcessStep::TYPE_APPROVAL, $osaTask->step_type);
        $this->assertNull($osaTask->available_at);
    }

    public function test_subsequent_submissions_are_idempotent_and_do_not_duplicate_runs_or_tasks(): void
    {
        [$organization, $organizer] = $this->setupOrganizationWithOrganizer();
        $event = $this->createSubmittableEvent($organization, $organizer);

        $permitService = app(EventPermitService::class);

        $firstSubmission = $permitService->submit($event, $organizer);
        $secondSubmission = $permitService->submit($event->fresh(), $organizer);
        $run1 = $firstSubmission->workflowRun()->firstOrFail();
        $run2 = $secondSubmission->workflowRun()->firstOrFail();

        $this->assertSame($run1->id, $run2->id);
        $this->assertSame(1, ProposalWorkflowRun::where('event_id', $event->id)->count());
        $this->assertSame(5, ProposalWorkflowTask::where('workflow_run_id', $run1->id)->count());
    }

    public function test_failed_first_submission_rolls_back_the_event_and_workflow_records(): void
    {
        [$organization, $organizer] = $this->setupOrganizationWithOrganizer();
        $event = $this->createSubmittableEvent($organization, $organizer);
        $initialScopeCount = EventParticipationScope::count();

        $this->mock(ProposalWorkflowService::class, function ($mock): void {
            $mock->shouldReceive('bindFirstSubmission')
                ->once()
                ->andThrow(new DomainException('Unable to bind workflow.'));
        });

        try {
            app(EventPermitService::class)->submit($event, $organizer);
            $this->fail('Expected first submission to fail.');
        } catch (DomainException $exception) {
            $this->assertSame('Unable to bind workflow.', $exception->getMessage());
        }

        $this->assertSame(Event::STATUS_DRAFT, $event->fresh()->status);
        $this->assertDatabaseCount('proposal_workflow_runs', 0);
        $this->assertDatabaseCount('proposal_workflow_tasks', 0);
        $this->assertSame($initialScopeCount, EventParticipationScope::count());
    }

    public function test_published_template_version_is_immutable(): void
    {
        $version = app(ProposalWorkflowService::class)->ensureDefaultPublishedTemplate();
        $this->assertSame(ProposalProcessTemplateVersion::STATUS_PUBLISHED, $version->status);

        // Modifying published version properties fails
        try {
            $version->update(['summary' => 'Attempted unauthorized change']);
            $this->fail('Expected DomainException was not thrown when updating published template version.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }

        // Deleting published version fails
        try {
            $version->delete();
            $this->fail('Expected DomainException was not thrown when deleting published template version.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('cannot be deleted', $e->getMessage());
        }

        // Modifying existing step of published version fails
        $step = $version->steps()->first();
        try {
            $step->update(['title' => 'Tampered Title']);
            $this->fail('Expected DomainException was not thrown when updating step of published version.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Cannot modify steps of a published template version', $e->getMessage());
        }

        // Creating new step in published version fails
        try {
            ProposalProcessStep::create([
                'template_version_id' => $version->id,
                'step_key' => 'unauthorized_step',
                'title' => 'Unauthorized Step',
                'step_type' => ProposalProcessStep::TYPE_APPROVAL,
                'display_order' => 99,
            ]);
            $this->fail('Expected DomainException was not thrown when creating step in published version.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Cannot modify steps of a published template version', $e->getMessage());
        }

        // Deleting step of published version fails
        try {
            $step->delete();
            $this->fail('Expected DomainException was not thrown when deleting step of published version.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Cannot delete steps of a published template version', $e->getMessage());
        }

        // Retiring a published version is allowed
        $retirer = User::factory()->create();
        $freshVersion = $version->fresh();
        $freshVersion->update([
            'status' => ProposalProcessTemplateVersion::STATUS_RETIRED,
            'retired_at' => now(),
            'retired_by' => $retirer->id,
        ]);
        $this->assertSame(ProposalProcessTemplateVersion::STATUS_RETIRED, $freshVersion->fresh()->status);
    }

    public function test_applicability_rules_route_to_specific_template_version(): void
    {
        [$organization, $organizer] = $this->setupOrganizationWithOrganizer();

        // Create an LSG specific template
        $customTemplate = ProposalProcessTemplate::create([
            'name' => 'College Student Council Fast-track Workflow',
            'status' => ProposalProcessTemplate::STATUS_ACTIVE,
        ]);

        $customVersion = ProposalProcessTemplateVersion::create([
            'template_id' => $customTemplate->id,
            'version_number' => 1,
            'status' => ProposalProcessTemplateVersion::STATUS_DRAFT,
            'summary' => 'Fast track workflow for college council.',
        ]);

        $customStep1 = ProposalProcessStep::create([
            'template_version_id' => $customVersion->id,
            'step_key' => 'council_internal_clearance',
            'title' => 'Council Internal Clearance',
            'step_type' => ProposalProcessStep::TYPE_INTAKE,
            'display_order' => 1,
            'is_required' => true,
        ]);

        $customStep2 = ProposalProcessStep::create([
            'template_version_id' => $customVersion->id,
            'step_key' => 'osa_direct_approval',
            'title' => 'OSA Direct Approval',
            'step_type' => ProposalProcessStep::TYPE_APPROVAL,
            'display_order' => 2,
            'is_required' => true,
        ]);

        ProposalProcessStepDependency::create([
            'step_id' => $customStep2->id,
            'prerequisite_step_id' => $customStep1->id,
        ]);

        // Specific applicability rule for lsg organization type with priority 10
        ProposalTemplateApplicabilityRule::create([
            'template_version_id' => $customVersion->id,
            'priority' => 10,
            'organization_type' => 'lsg',
            'status' => ProposalTemplateApplicabilityRule::STATUS_ACTIVE,
        ]);

        $customVersion->update([
            'status' => ProposalProcessTemplateVersion::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);

        // Event from an organization with organization_type = 'lsg'
        $organization->update(['type' => 'lsg']);
        $event = $this->createSubmittableEvent($organization, $organizer);

        $workflowService = app(ProposalWorkflowService::class);
        $run = $workflowService->bindFirstSubmission($event, $organizer);

        $this->assertSame($customVersion->id, $run->template_version_id);
        $tasks = $run->tasks()->orderBy('display_order')->get();
        $this->assertCount(2, $tasks);
        $this->assertSame('council_internal_clearance', $tasks[0]->step_key);
        $this->assertSame(ProposalWorkflowTask::STATUS_AVAILABLE, $tasks[0]->status);
        $this->assertSame('osa_direct_approval', $tasks[1]->step_key);
        $this->assertSame(ProposalWorkflowTask::STATUS_BLOCKED, $tasks[1]->status);
    }

    /**
     * @return array{0: Organization, 1: User}
     */
    private function setupOrganizationWithOrganizer(): array
    {
        $schoolYear = SchoolYear::create([
            'label' => '2026-2027',
            'starts_on' => today()->subMonths(3),
            'ends_on' => today()->addMonths(9),
            'status' => SchoolYear::STATUS_ACTIVE,
            'activated_at' => now(),
        ]);
        $college = College::factory()->create();
        $department = Department::factory()->create([
            'college_id' => $college->id,
            'name' => 'Department of Computer Science',
            'code' => 'DCS'.mb_strtoupper(str()->random(5)),
        ]);
        $organization = Organization::create([
            'name' => 'Computer Students Society',
            'acronym' => 'CSS',
            'type' => 'society',
            'college_id' => $college->id,
            'department_id' => $department->id,
        ]);

        $organizer = User::factory()->create([
            'college_id' => $college->id,
            'department_id' => $department->id,
            'email' => 'organizer.'.uniqid().'@usm.edu.ph',
        ]);
        $secretary = User::factory()->create([
            'college_id' => $college->id,
            'department_id' => $department->id,
            'email' => 'secretary.'.uniqid().'@usm.edu.ph',
        ]);
        $adviser = User::factory()->create([
            'college_id' => $college->id,
            'department_id' => $department->id,
            'user_type' => 'faculty',
            'email' => 'adviser.'.uniqid().'@usm.edu.ph',
        ]);
        $chair = User::factory()->create([
            'college_id' => $college->id,
            'department_id' => $department->id,
            'user_type' => 'faculty',
            'email' => 'chair.'.uniqid().'@usm.edu.ph',
        ]);
        $dean = User::factory()->create([
            'college_id' => $college->id,
            'user_type' => 'faculty',
            'email' => 'dean.'.uniqid().'@usm.edu.ph',
        ]);
        $security = User::factory()->create(['email' => 'security.'.uniqid().'@usm.edu.ph']);
        $security->assignRole('Chief Security');
        $osa = User::factory()->create(['email' => 'osa.'.uniqid().'@usm.edu.ph']);
        $osa->assignRole('Super Admin (OSA)');

        app(AdminAssignmentService::class)->createAssignment(
            user: $organizer,
            roleName: 'Society Admin',
            organization: $organization,
            college: $college,
            academicYear: $schoolYear->label,
            termStart: today()->subMonth()->toDateString(),
            termEnd: today()->addYear()->toDateString(),
            positionTitle: 'President',
            approvedBy: $organizer,
            isPrimaryAdmin: true,
        );

        AdminAssignment::create([
            'user_id' => $chair->id,
            'role_name' => 'Department Chairperson',
            'position_title' => 'Chairperson, Department of Computer Science',
            'college_id' => $college->id,
            'department_id' => $department->id,
            'school_year_id' => $schoolYear->id,
            'academic_year' => $schoolYear->label,
            'status' => AdminAssignment::STATUS_ACTIVE,
            'is_primary_admin' => true,
            'approved_at' => now(),
        ]);

        AdminAssignment::create([
            'user_id' => $dean->id,
            'role_name' => 'College Dean',
            'position_title' => 'Dean',
            'college_id' => $college->id,
            'school_year_id' => $schoolYear->id,
            'academic_year' => $schoolYear->label,
            'status' => AdminAssignment::STATUS_ACTIVE,
            'is_primary_admin' => true,
            'approved_at' => now(),
        ]);

        AdminAssignment::create([
            'user_id' => $security->id,
            'role_name' => 'Chief Security',
            'position_title' => 'Chief Security Officer',
            'school_year_id' => $schoolYear->id,
            'academic_year' => $schoolYear->label,
            'status' => AdminAssignment::STATUS_ACTIVE,
            'is_primary_admin' => true,
            'approved_at' => now(),
        ]);

        AdminAssignment::create([
            'user_id' => $osa->id,
            'role_name' => 'Super Admin (OSA)',
            'position_title' => 'OSA Workflow Approver',
            'school_year_id' => $schoolYear->id,
            'academic_year' => $schoolYear->label,
            'status' => AdminAssignment::STATUS_ACTIVE,
            'is_primary_admin' => true,
            'approved_at' => now(),
        ]);

        $roster = OrganizationOfficerRoster::create([
            'organization_id' => $organization->id,
            'school_year_id' => $schoolYear->id,
            'status' => OrganizationOfficerRoster::STATUS_VERIFIED,
            'submitted_by' => $organizer->id,
            'submitted_at' => now()->subDays(4),
            'verified_by' => $adviser->id,
            'verified_at' => now()->subDays(3),
        ]);

        foreach ([
            [$organizer, OrganizationOfficer::POSITION_PRESIDENT, 'President'],
            [$secretary, OrganizationOfficer::POSITION_SECRETARY, 'Secretary'],
            [$adviser, OrganizationOfficer::POSITION_ADVISER, 'Faculty Adviser'],
        ] as [$user, $positionKey, $positionTitle]) {
            OrganizationOfficer::create([
                'officer_roster_id' => $roster->id,
                'user_id' => $user->id,
                'position_key' => $positionKey,
                'position_title' => $positionTitle,
                'status' => OrganizationOfficer::STATUS_ACTIVE,
                'effective_start' => $schoolYear->starts_on,
            ]);
        }

        return [$organization, $organizer->fresh()];
    }

    private function createSubmittableEvent(Organization $organization, User $organizer): Event
    {
        $venue = Venue::factory()->create(['managing_office' => 'Campus Facilities Office']);

        Storage::put('proposal-documents/proposal.pdf', 'dummy-ppa');
        Storage::put('venue-confirmations/confirmation.pdf', 'dummy-confirmation');

        $event = Event::create([
            'organization_id' => $organization->id,
            'school_year_id' => SchoolYear::where('status', SchoolYear::STATUS_ACTIVE)->value('id'),
            'department_id' => $organization->department_id,
            'title' => 'Annual Coding Summit',
            'proposal_category' => 'activity',
            'status' => Event::STATUS_DRAFT,
            'lifecycle_phase' => 'scheduled',
            'event_domain' => 'student_affairs',
            'start_date' => today()->addMonth(),
            'end_date' => today()->addMonth(),
            'location_type' => 'on-campus',
            'venue_id' => $venue->id,
            'location_details' => $venue->displayName(),
            'venue_confirmation_office' => $venue->managing_office,
            'venue_confirmation_date' => today(),
            'venue_confirmation_reference_number' => 'VC-101',
            'venue_confirmation_document_path' => 'venue-confirmations/confirmation.pdf',
            'venue_confirmation_document_original_name' => 'confirmation.pdf',
            'proposal_document_path' => 'proposal-documents/proposal.pdf',
            'proposal_document_original_name' => 'proposal.pdf',
            'college_name_snapshot' => $organization->resolvedCollege()?->name,
            'department_name_snapshot' => $organization->department?->name,
            'organization_name_snapshot' => $organization->name,
            'proponent_name_snapshot' => $organizer->name,
            'proponent_user_id' => $organizer->id,
            'org_head_user_id' => $organizer->id,
            'dept_chair_user_id' => AdminAssignment::where('role_name', 'Department Chairperson')->where('department_id', $organization->department_id)->value('user_id'),
            'college_dean_user_id' => AdminAssignment::where('role_name', 'College Dean')->where('college_id', $organization->college_id)->value('user_id'),
        ]);

        EventDay::create([
            'event_id' => $event->id,
            'day_number' => 1,
            'date' => today()->addMonth(),
            'start_time' => '08:00',
            'end_time' => '17:00',
        ]);

        EventParticipationScope::create([
            'event_id' => $event->id,
            'organization_id' => $organization->id,
            'college_id' => $organization->college_id,
            'participation_role' => EventParticipationScope::ROLE_ORGANIZER,
            'ppa_status' => EventParticipationScope::PPA_DRAFT,
            'requires_fee' => false,
            'fee_amount' => null,
            'submitted_by' => null,
            'submitted_at' => null,
        ]);

        CanonicalPpaPacket::factory()->for($event)->create([
            'proposal_category' => 'activity',
            'title' => $event->title,
            'proponent_name' => 'Organizer Lead',
            'proponent_position' => 'President',
            'proponent_organization' => $organization->name,
            'proponent_email' => $organizer->email,
            'proponent_contact_number' => '09170000000',
            'implementing_office' => $organization->name,
            'source_of_fund' => 'Organization fund',
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
            'rationale' => 'Annual Coding Summit for tech students.',
            'general_objective' => 'Promote technology awareness.',
            'specific_objectives' => ['Learn new technologies', 'Network with peers'],
            'methodology' => 'Conduct workshops and coding competitions.',
            'program_activities' => [[
                'date' => today()->addMonth()->toDateString(),
                'time' => '08:00 - 17:00',
                'activity' => $event->title,
                'person_in_charge' => 'Organizer Lead',
            ]],
            'expected_output' => 'Completed software projects.',
            'beneficiaries' => 'Computer science students',
            'budget_breakdown' => [['item' => 'Total budget', 'amount' => '1500.00']],
        ]);

        return $event;
    }
}
