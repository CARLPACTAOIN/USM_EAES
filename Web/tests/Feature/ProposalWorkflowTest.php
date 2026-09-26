<?php

namespace Tests\Feature;

use App\Models\CanonicalPpaPacket;
use App\Models\College;
use App\Models\Event;
use App\Models\EventDay;
use App\Models\EventParticipationScope;
use App\Models\Organization;
use App\Models\ProposalProcessStep;
use App\Models\ProposalProcessStepDependency;
use App\Models\ProposalProcessTemplate;
use App\Models\ProposalProcessTemplateVersion;
use App\Models\ProposalTemplateApplicabilityRule;
use App\Models\ProposalWorkflowRun;
use App\Models\ProposalWorkflowTask;
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

        // Task 4: Venue Clearance (blocked by adviser)
        $venueTask = $tasks->firstWhere('step_key', 'venue_clearance');
        $this->assertNotNull($venueTask);
        $this->assertSame(ProposalWorkflowTask::STATUS_BLOCKED, $venueTask->status);
        $this->assertSame(4, $venueTask->display_order);
        $this->assertSame(ProposalProcessStep::TYPE_CLEARANCE, $venueTask->step_type);
        $this->assertNull($venueTask->available_at);

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

        $workflowService = app(ProposalWorkflowService::class);

        $run1 = $workflowService->bindFirstSubmission($event, $organizer);
        $run2 = $workflowService->bindFirstSubmission($event, $organizer);

        $this->assertSame($run1->id, $run2->id);
        $this->assertSame(1, ProposalWorkflowRun::where('event_id', $event->id)->count());
        $this->assertSame(5, ProposalWorkflowTask::where('workflow_run_id', $run1->id)->count());
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
        $college = College::factory()->create();
        $organization = Organization::create([
            'name' => 'Computer Students Society',
            'acronym' => 'CSS',
            'type' => 'society',
            'college_id' => $college->id,
        ]);

        $organizer = User::factory()->create([
            'college_id' => $college->id,
            'email' => 'organizer.'.uniqid().'@usm.edu.ph',
        ]);

        app(AdminAssignmentService::class)->createAssignment(
            user: $organizer,
            roleName: 'Society Admin',
            organization: $organization,
            college: $college,
            academicYear: '2026-2027',
            termStart: today()->subMonth()->toDateString(),
            termEnd: today()->addYear()->toDateString(),
            positionTitle: 'President',
            approvedBy: $organizer,
            isPrimaryAdmin: true,
        );

        return [$organization, $organizer->fresh()];
    }

    private function createSubmittableEvent(Organization $organization, User $organizer): Event
    {
        $venue = Venue::factory()->create(['managing_office' => 'Campus Facilities Office']);

        Storage::put('proposal-documents/proposal.pdf', 'dummy-ppa');
        Storage::put('venue-confirmations/confirmation.pdf', 'dummy-confirmation');

        $event = Event::create([
            'organization_id' => $organization->id,
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
            'hardcopy_submitted' => true,
            'hardcopy_submitted_at' => now(),
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
