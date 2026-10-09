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
use App\Models\ProposalProcessTemplateVersion;
use App\Models\ProposalTemplateApplicabilityRule;
use App\Models\ProposalWorkflowRun;
use App\Models\SchoolYear;
use App\Models\User;
use App\Models\Venue;
use App\Services\AdminAssignmentService;
use App\Services\EventPermitService;
use Database\Seeders\RolePermissionSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProposalProcessTemplateManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $osa;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Storage::fake('local');

        $this->osa = User::factory()->create([
            'email' => 'osa.templates@usm.edu.ph',
        ]);
        $this->osa->assignRole('Super Admin (OSA)');
    }

    public function test_osa_can_create_and_edit_a_template_draft_with_steps_prerequisites_and_rules(): void
    {
        $college = College::factory()->create();

        $response = $this->actingAs($this->osa)
            ->post(route('dashboard.proposal-process-templates.store'), $this->templatePayload($college, [
                'template_name' => 'College Event Workflow',
                'summary' => 'Initial draft.',
            ]));

        $response->assertRedirect(route('dashboard.proposal-process-templates.index', [
            'version' => ProposalProcessTemplateVersion::query()->first()->id,
        ]));
        $response->assertSessionHasNoErrors();

        $version = ProposalProcessTemplateVersion::query()->with(['template', 'steps.prerequisiteSteps', 'applicabilityRules'])->first();
        $this->assertSame(ProposalProcessTemplateVersion::STATUS_DRAFT, $version->status);
        $this->assertSame('College Event Workflow', $version->template->name);
        $this->assertCount(2, $version->steps);
        $this->assertSame(['adviser_endorsement'], $version->steps->firstWhere('step_key', 'osa_review')->prerequisiteSteps->pluck('step_key')->all());
        $this->assertSame($college->id, $version->applicabilityRules->first()->college_id);

        $this->actingAs($this->osa)
            ->put(route('dashboard.proposal-process-template-versions.update', $version), $this->templatePayload($college, [
                'template_name' => 'College Event Workflow Revised',
                'summary' => 'Added security clearance.',
                'steps' => [
                    [
                        'step_key' => 'security_clearance',
                        'title' => 'Security Clearance',
                        'description' => 'Security office review.',
                        'step_type' => ProposalProcessStep::TYPE_CLEARANCE,
                        'phase' => 'clearance',
                        'display_order' => 1,
                        'is_required' => '1',
                        'is_gate' => '0',
                        'assigned_role' => 'chief_security',
                        'assigned_scope' => 'university',
                        'prerequisites' => [],
                    ],
                    [
                        'step_key' => 'osa_review',
                        'title' => 'OSA Review',
                        'description' => 'Final OSA decision.',
                        'step_type' => ProposalProcessStep::TYPE_APPROVAL,
                        'phase' => 'approval',
                        'display_order' => 2,
                        'is_required' => '1',
                        'is_gate' => '1',
                        'assigned_role' => 'osa',
                        'assigned_scope' => 'university',
                        'prerequisites' => ['security_clearance'],
                    ],
                ],
            ]))
            ->assertRedirect(route('dashboard.proposal-process-templates.index', ['version' => $version->id]))
            ->assertSessionHasNoErrors();

        $version->refresh()->load(['template', 'steps.prerequisiteSteps']);

        $this->assertSame('College Event Workflow Revised', $version->template->name);
        $this->assertSame('Added security clearance.', $version->summary);
        $this->assertSame(['security_clearance'], $version->steps->firstWhere('step_key', 'osa_review')->prerequisiteSteps->pluck('step_key')->all());
    }

    public function test_publishing_locks_the_version_and_future_edits_require_a_new_draft(): void
    {
        $version = $this->createDraftVersion();

        $this->actingAs($this->osa)
            ->post(route('dashboard.proposal-process-template-versions.publish', $version))
            ->assertRedirect(route('dashboard.proposal-process-templates.index', ['version' => $version->id]));

        $version->refresh();
        $this->assertSame(ProposalProcessTemplateVersion::STATUS_PUBLISHED, $version->status);
        $this->assertSame($this->osa->id, $version->published_by);

        $this->actingAs($this->osa)
            ->put(route('dashboard.proposal-process-template-versions.update', $version), $this->templatePayload(null, [
                'template_name' => 'Tampered Published Version',
            ]))
            ->assertSessionHasErrors('version');

        $step = $version->steps()->first();
        try {
            $step->update(['title' => 'Tampered Step']);
            $this->fail('Expected published step mutation to fail.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('published template version', $exception->getMessage());
        }

        $rule = $version->applicabilityRules()->first();
        try {
            $rule->update(['priority' => 999]);
            $this->fail('Expected published applicability rule mutation to fail.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('locked template version', $exception->getMessage());
        }
    }

    public function test_new_draft_from_published_version_preserves_locked_version_and_can_be_edited(): void
    {
        $version = $this->createDraftVersion();
        $this->actingAs($this->osa)->post(route('dashboard.proposal-process-template-versions.publish', $version));
        $version->refresh();

        $this->actingAs($this->osa)
            ->post(route('dashboard.proposal-process-template-versions.draft', $version))
            ->assertSessionHasNoErrors();

        $draft = ProposalProcessTemplateVersion::query()
            ->where('template_id', $version->template_id)
            ->where('version_number', 2)
            ->with('steps')
            ->firstOrFail();

        $this->assertSame(ProposalProcessTemplateVersion::STATUS_DRAFT, $draft->status);
        $this->assertSame(ProposalProcessTemplateVersion::STATUS_PUBLISHED, $version->fresh()->status);
        $this->assertSame($version->steps()->count(), $draft->steps()->count());

        $this->actingAs($this->osa)
            ->put(route('dashboard.proposal-process-template-versions.update', $draft), $this->templatePayload(null, [
                'template_name' => 'Editable V2 Workflow',
                'summary' => 'V2 adjustments.',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame('Editable V2 Workflow', $draft->fresh()->template->name);
    }

    public function test_retiring_prevents_new_bindings_but_preserves_existing_workflow_runs(): void
    {
        [$organization, $organizer] = $this->setupOrganizationWithOrganizer();
        $version = $this->createDraftVersion(null, [
            'rules' => [[
                'priority' => '50',
                'organization_type' => 'society',
                'event_domain' => 'student_affairs',
                'location_type' => 'on-campus',
                'status' => ProposalTemplateApplicabilityRule::STATUS_ACTIVE,
                'is_default' => '0',
            ]],
        ]);

        $this->actingAs($this->osa)->post(route('dashboard.proposal-process-template-versions.publish', $version));
        $version->refresh();

        $firstEvent = $this->createSubmittableEvent($organization, $organizer, 'First Event');
        app(EventPermitService::class)->submit($firstEvent, $organizer);
        $run = ProposalWorkflowRun::where('event_id', $firstEvent->id)->firstOrFail();
        $this->assertSame($version->id, $run->template_version_id);

        $this->actingAs($this->osa)
            ->post(route('dashboard.proposal-process-template-versions.retire', $version))
            ->assertSessionHasNoErrors();

        $version->refresh();
        $this->assertSame(ProposalProcessTemplateVersion::STATUS_RETIRED, $version->status);
        $this->assertSame($version->id, $run->fresh()->template_version_id);

        $retiredRule = $version->applicabilityRules()->first();
        try {
            $retiredRule->update(['priority' => 999]);
            $this->fail('Expected retired applicability rule mutation to fail.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('locked template version', $exception->getMessage());
        }

        $secondEvent = $this->createSubmittableEvent($organization, $organizer, 'Second Event');
        app(EventPermitService::class)->submit($secondEvent, $organizer);
        $secondRun = ProposalWorkflowRun::where('event_id', $secondEvent->id)->firstOrFail();

        $this->assertNotSame($version->id, $secondRun->template_version_id);
    }

    public function test_default_template_is_created_when_no_active_published_rule_exists(): void
    {
        [$organization, $organizer] = $this->setupOrganizationWithOrganizer();
        $event = $this->createSubmittableEvent($organization, $organizer, 'Fallback Event');

        app(EventPermitService::class)->submit($event, $organizer);

        $run = ProposalWorkflowRun::where('event_id', $event->id)->with('templateVersion.template')->firstOrFail();
        $this->assertSame('Standard Event Proposal Workflow', $run->templateVersion->template->name);
        $this->assertSame(ProposalProcessTemplateVersion::STATUS_PUBLISHED, $run->templateVersion->status);
    }

    public function test_draft_prerequisites_must_reference_earlier_steps(): void
    {
        $version = $this->createDraftVersion();

        $payload = $this->templatePayload(null, [
            'steps' => [
                [
                    'step_key' => 'osa_review',
                    'title' => 'OSA Review',
                    'description' => 'Final OSA decision.',
                    'step_type' => ProposalProcessStep::TYPE_APPROVAL,
                    'phase' => 'approval',
                    'display_order' => 1,
                    'is_required' => '1',
                    'is_gate' => '1',
                    'assigned_role' => 'osa',
                    'assigned_scope' => 'university',
                    'prerequisites' => ['adviser_endorsement'],
                ],
                [
                    'step_key' => 'adviser_endorsement',
                    'title' => 'Adviser Endorsement',
                    'description' => 'Adviser validates the proposal.',
                    'step_type' => ProposalProcessStep::TYPE_ENDORSEMENT,
                    'phase' => 'endorsement',
                    'display_order' => 2,
                    'is_required' => '1',
                    'is_gate' => '0',
                    'assigned_role' => 'adviser',
                    'assigned_scope' => 'organization',
                    'prerequisites' => [],
                ],
            ],
        ]);

        $this->actingAs($this->osa)
            ->put(route('dashboard.proposal-process-template-versions.update', $version), $payload)
            ->assertSessionHasErrors('steps.0.prerequisites');
    }

    public function test_osa_can_view_proposal_process_templates_index_with_metrics_and_pipelines(): void
    {
        $version = $this->createDraftVersion();

        $response = $this->actingAs($this->osa)
            ->get(route('dashboard.proposal-process-templates.index'));

        $response->assertOk();
        $response->assertSee('Workflow Templates');
        $response->assertSee('Template Catalog');
        $response->assertSee('Active Workflows');
        $response->assertSee('Draft Versions');
        $response->assertSee('Proposals Processed');
        $response->assertSee($version->template->name);
        $response->assertSee('Version 1');
        $response->assertSee('Adviser Endorsement');
        $response->assertSee('adviser_endorsement');
        $response->assertSee('OSA Review');
        $response->assertSee('Gate');
    }

    public function test_non_osa_user_is_forbidden_from_viewing_proposal_process_templates_index(): void
    {
        $student = User::factory()->create([
            'email' => 'student.templates@usm.edu.ph',
        ]);
        $student->assignRole('Student');

        $this->actingAs($student)
            ->get(route('dashboard.proposal-process-templates.index'))
            ->assertForbidden();
    }

    private function createDraftVersion(?College $college = null, array $overrides = []): ProposalProcessTemplateVersion
    {
        $college ??= College::factory()->create();

        $this->actingAs($this->osa)
            ->post(route('dashboard.proposal-process-templates.store'), $this->templatePayload($college, $overrides))
            ->assertSessionHasNoErrors();

        return ProposalProcessTemplateVersion::query()->latest()->firstOrFail();
    }

    private function templatePayload(?College $college, array $overrides = []): array
    {
        $payload = array_replace_recursive([
            'template_name' => 'Society Proposal Workflow',
            'template_description' => 'Configurable OSA workflow.',
            'summary' => 'Initial draft.',
            'steps' => [
                [
                    'step_key' => 'adviser_endorsement',
                    'title' => 'Adviser Endorsement',
                    'description' => 'Adviser validates the proposal.',
                    'step_type' => ProposalProcessStep::TYPE_ENDORSEMENT,
                    'phase' => 'endorsement',
                    'display_order' => 1,
                    'is_required' => '1',
                    'is_gate' => '0',
                    'assigned_role' => 'adviser',
                    'assigned_scope' => 'organization',
                    'prerequisites' => [],
                ],
                [
                    'step_key' => 'osa_review',
                    'title' => 'OSA Review',
                    'description' => 'OSA makes the final decision.',
                    'step_type' => ProposalProcessStep::TYPE_APPROVAL,
                    'phase' => 'approval',
                    'display_order' => 2,
                    'is_required' => '1',
                    'is_gate' => '1',
                    'assigned_role' => 'osa',
                    'assigned_scope' => 'university',
                    'prerequisites' => ['adviser_endorsement'],
                ],
            ],
            'rules' => [
                [
                    'priority' => '10',
                    'organizer_role' => '',
                    'organization_type' => 'society',
                    'college_id' => $college?->id,
                    'event_domain' => 'student_affairs',
                    'activity_category' => 'activity',
                    'location_type' => 'on-campus',
                    'is_default' => '0',
                    'status' => ProposalTemplateApplicabilityRule::STATUS_ACTIVE,
                    'effective_start' => '',
                    'effective_end' => '',
                ],
            ],
        ], $overrides);

        if (array_key_exists('steps', $overrides)) {
            $payload['steps'] = $overrides['steps'];
        }

        if (array_key_exists('rules', $overrides)) {
            $payload['rules'] = $overrides['rules'];
        }

        return $payload;
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
            'status' => 'active',
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
            'user_id' => $this->osa->id,
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

    private function createSubmittableEvent(Organization $organization, User $organizer, string $title): Event
    {
        $venue = Venue::factory()->create(['managing_office' => 'Campus Facilities Office']);

        Storage::put('proposal-documents/proposal.pdf', 'dummy-ppa');
        Storage::put('venue-confirmations/confirmation.pdf', 'dummy-confirmation');

        $event = Event::create([
            'organization_id' => $organization->id,
            'school_year_id' => SchoolYear::where('status', SchoolYear::STATUS_ACTIVE)->value('id'),
            'department_id' => $organization->department_id,
            'title' => $title,
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
            'rationale' => 'Event rationale for proposal.',
            'general_objective' => 'Promote student engagement.',
            'specific_objectives' => ['Engage members', 'Document participation'],
            'methodology' => 'Conduct the event on campus.',
            'program_activities' => [[
                'date' => today()->addMonth()->toDateString(),
                'time' => '08:00 - 17:00',
                'activity' => $event->title,
                'person_in_charge' => 'Organizer Lead',
            ]],
            'expected_output' => 'Completed event.',
            'beneficiaries' => 'Students',
            'budget_breakdown' => [['item' => 'Total budget', 'amount' => '1500.00']],
        ]);

        return $event;
    }
}
