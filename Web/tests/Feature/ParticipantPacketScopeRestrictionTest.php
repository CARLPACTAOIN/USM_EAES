<?php

namespace Tests\Feature;

use App\Livewire\Dashboard\EventProposalCreate;
use App\Models\CanonicalPpaPacket;
use App\Models\College;
use App\Models\Department;
use App\Models\Event;
use App\Models\EventParticipationScope;
use App\Models\Organization;
use App\Models\SchoolYear;
use App\Models\University;
use App\Models\User;
use App\Services\AdminAssignmentService;
use App\Services\EventProposalDraftService;
use App\Services\EventProposalSubmissionGuard;
use App\Services\ParticipantPpaPacketService;
use App\Services\ParticipationScopeService;
use Database\Seeders\RolePermissionSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class ParticipantPacketScopeRestrictionTest extends TestCase
{
    use RefreshDatabase;

    protected SchoolYear $schoolYear;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Storage::fake('local');

        $this->schoolYear = SchoolYear::factory()->create([
            'label' => '2026-2027',
            'starts_on' => '2026-07-01',
            'ends_on' => '2027-06-30',
            'status' => SchoolYear::STATUS_ACTIVE,
        ]);
    }

    public function test_web_proposal_create_accepts_organizer_and_co_organizer_roles(): void
    {
        $h = $this->createHierarchy();
        $admin = $this->createAdmin($h['organizer']);

        // 1. Organizer role
        $payload1 = $this->baseWebPayload(['activity_role' => CanonicalPpaPacket::ROLE_ORGANIZER, 'title' => 'Organizer Role PPA']);
        $this->actingAs($admin)
            ->post(route('dashboard.events.create'), $payload1)
            ->assertRedirect(route('dashboard.events'))
            ->assertSessionHas('success');

        $event1 = Event::with('canonicalPpaPacket')->where('title', 'Organizer Role PPA')->firstOrFail();
        $this->assertNotNull($event1->canonicalPpaPacket);
        $this->assertSame(CanonicalPpaPacket::ROLE_ORGANIZER, $event1->canonicalPpaPacket->activity_role);
        $this->assertNull($event1->canonicalPpaPacket->event_participation_scope_id);

        // 2. Co-Organizer role
        $payload2 = $this->baseWebPayload(['activity_role' => CanonicalPpaPacket::ROLE_CO_ORGANIZER, 'title' => 'Co-Organizer Role PPA']);
        $this->actingAs($admin)
            ->post(route('dashboard.events.create'), $payload2)
            ->assertRedirect(route('dashboard.events'))
            ->assertSessionHas('success');

        $event2 = Event::with('canonicalPpaPacket')->where('title', 'Co-Organizer Role PPA')->firstOrFail();
        $this->assertNotNull($event2->canonicalPpaPacket);
        $this->assertSame(CanonicalPpaPacket::ROLE_CO_ORGANIZER, $event2->canonicalPpaPacket->activity_role);
        $this->assertNull($event2->canonicalPpaPacket->event_participation_scope_id);
    }

    public function test_web_proposal_create_rejects_participant_role(): void
    {
        $h = $this->createHierarchy();
        $admin = $this->createAdmin($h['organizer']);

        $payload = $this->baseWebPayload([
            'activity_role' => CanonicalPpaPacket::ROLE_PARTICIPANT,
            'title' => 'Illegal Direct Participant Packet',
        ]);

        $this->actingAs($admin)
            ->post(route('dashboard.events.create'), $payload)
            ->assertSessionHasErrors('activity_role');

        $this->assertDatabaseMissing('events', ['title' => 'Illegal Direct Participant Packet']);
        $this->assertDatabaseMissing('canonical_ppa_packets', ['title' => 'Illegal Direct Participant Packet']);
    }

    public function test_web_proposal_update_rejects_participant_role(): void
    {
        $h = $this->createHierarchy();
        $admin = $this->createAdmin($h['organizer']);

        $event = $this->createEvent($h['organizer'], 'Legit Event Draft');
        CanonicalPpaPacket::factory()->for($event)->create([
            'activity_role' => CanonicalPpaPacket::ROLE_ORGANIZER,
            'event_participation_scope_id' => null,
            'title' => 'Legit Event Draft',
        ]);

        $originalTitle = $event->title;

        $payload = $this->baseWebPayload([
            'activity_role' => CanonicalPpaPacket::ROLE_PARTICIPANT,
            'title' => 'Attempted Role Hijack',
        ]);

        $this->actingAs($admin)
            ->post(route('dashboard.events.update', $event), $payload)
            ->assertSessionHasErrors('activity_role');

        $event->refresh();
        $this->assertSame(CanonicalPpaPacket::ROLE_ORGANIZER, $event->canonicalPpaPacket->activity_role);
        $this->assertSame($originalTitle, $event->title);
    }

    public function test_livewire_proposal_create_rejects_participant_role(): void
    {
        $h = $this->createHierarchy();
        $admin = $this->createAdmin($h['organizer']);
        $eventDate = today()->addDays(10)->toDateString();

        Livewire::actingAs($admin)
            ->test(EventProposalCreate::class)
            ->set('title', 'Livewire Direct Participant Attempt')
            ->set('proposalCategory', 'activity')
            ->set('activityRole', CanonicalPpaPacket::ROLE_PARTICIPANT)
            ->set('startDate', $eventDate)
            ->set('endDate', $eventDate)
            ->set('locationType', 'off-campus')
            ->set('locationDetails', 'Conference Center')
            ->set('eventDays', [[
                'date' => $eventDate,
                'start_time' => '08:00',
                'end_time' => '12:00',
            ]])
            ->set('evaluationQuestions', $this->customEvaluationQuestions())
            ->call('createProposal')
            ->assertHasErrors(['activityRole' => 'in']);

        $this->assertDatabaseMissing('events', ['title' => 'Livewire Direct Participant Attempt']);
    }

    public function test_livewire_proposal_create_accepts_organizer_and_co_organizer_roles(): void
    {
        $h = $this->createHierarchy();
        $admin = $this->createAdmin($h['organizer']);
        $eventDate = today()->addDays(10)->toDateString();

        Livewire::actingAs($admin)
            ->test(EventProposalCreate::class)
            ->set('title', 'Livewire Co-Organizer Test')
            ->set('proposalCategory', 'activity')
            ->set('activityRole', CanonicalPpaPacket::ROLE_CO_ORGANIZER)
            ->set('startDate', $eventDate)
            ->set('endDate', $eventDate)
            ->set('locationType', 'off-campus')
            ->set('locationDetails', 'Conference Center')
            ->set('eventDays', [[
                'date' => $eventDate,
                'start_time' => '08:00',
                'end_time' => '12:00',
            ]])
            ->set('evaluationQuestions', $this->customEvaluationQuestions())
            ->call('createProposal')
            ->assertHasNoErrors()
            ->assertRedirect(route('dashboard.events'));

        $event = Event::with('canonicalPpaPacket')->where('title', 'Livewire Co-Organizer Test')->firstOrFail();
        $this->assertSame(CanonicalPpaPacket::ROLE_CO_ORGANIZER, $event->canonicalPpaPacket->activity_role);
    }

    public function test_api_proposal_store_rejects_participant_role_top_level_and_canonical_packet(): void
    {
        $h = $this->createHierarchy();
        $admin = $this->createAdmin($h['organizer']);
        $token = $admin->createToken('test-api-token')->plainTextToken;

        // Top level activity_role = participant
        $payload1 = $this->baseApiPayload(['activity_role' => CanonicalPpaPacket::ROLE_PARTICIPANT]);
        $this->withToken($token)
            ->postJson('/api/v1/events', $payload1)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('activity_role');

        // Inside canonical_packet.activity_role = participant
        $payload2 = $this->baseApiPayload([
            'canonical_packet' => ['activity_role' => CanonicalPpaPacket::ROLE_PARTICIPANT],
        ]);
        $this->withToken($token)
            ->postJson('/api/v1/events', $payload2)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('canonical_packet.activity_role');
    }

    public function test_api_proposal_store_accepts_organizer_and_co_organizer_roles(): void
    {
        $h = $this->createHierarchy();
        $admin = $this->createAdmin($h['organizer']);
        $token = $admin->createToken('test-api-token')->plainTextToken;

        $payload = $this->baseApiPayload([
            'title' => 'API Co-Organizer Proposal',
            'activity_role' => CanonicalPpaPacket::ROLE_CO_ORGANIZER,
            'canonical_packet' => [
                'activity_role' => CanonicalPpaPacket::ROLE_CO_ORGANIZER,
            ],
        ]);

        $this->withToken($token)
            ->postJson('/api/v1/events', $payload)
            ->assertCreated()
            ->assertJsonPath('event.canonical_ppa_packet.activity_role', CanonicalPpaPacket::ROLE_CO_ORGANIZER);

        $event = Event::with('canonicalPpaPacket')->where('title', 'API Co-Organizer Proposal')->firstOrFail();
        $this->assertSame(CanonicalPpaPacket::ROLE_CO_ORGANIZER, $event->canonicalPpaPacket->activity_role);
    }

    public function test_api_proposal_update_rejects_participant_role(): void
    {
        $h = $this->createHierarchy();
        $admin = $this->createAdmin($h['organizer']);
        $token = $admin->createToken('test-api-token')->plainTextToken;

        $event = $this->createEvent($h['organizer'], 'API Editable Event');
        CanonicalPpaPacket::factory()->for($event)->create([
            'activity_role' => CanonicalPpaPacket::ROLE_ORGANIZER,
            'title' => 'API Editable Event',
        ]);

        $payload = $this->baseApiPayload([
            'title' => 'API Updated Title',
            'activity_role' => CanonicalPpaPacket::ROLE_PARTICIPANT,
        ]);

        $this->withToken($token)
            ->patchJson('/api/v1/events/'.$event->id, $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('activity_role');
    }

    public function test_event_proposal_draft_service_rejects_participant_role_directly(): void
    {
        $h = $this->createHierarchy();
        $admin = $this->createAdmin($h['organizer']);
        $service = app(EventProposalDraftService::class);

        // create() throws DomainException
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Participant packets cannot be created from organizer proposal paths.');

        $service->create($admin, [
            'organization_id' => $h['organizer']->id,
            'title' => 'Direct Service Participant Test',
            'proposal_category' => 'activity',
            'activity_role' => CanonicalPpaPacket::ROLE_PARTICIPANT,
            'start_date' => today()->addDays(5)->toDateString(),
            'end_date' => today()->addDays(5)->toDateString(),
            'location_type' => 'on-campus',
            'event_days' => [[
                'date' => today()->addDays(5)->toDateString(),
                'start_time' => '08:00',
                'end_time' => '12:00',
            ]],
            'evaluation_questions' => $this->customEvaluationQuestions(),
        ], null, null);
    }

    public function test_invited_participant_flow_creates_and_submits_participant_packet(): void
    {
        $h = $this->createHierarchy();
        $organizerAdmin = $this->createAdmin($h['organizer']);
        $partnerOrg = $this->createOrganization($h['college'], 'Invited Participant Org', 'IPO');
        $partnerAdmin = $this->createAdmin($partnerOrg);

        $event = $this->createEvent($h['organizer'], 'Collaborative Campus Fest');
        $organizerPacket = CanonicalPpaPacket::factory()->for($event)->create([
            'activity_role' => CanonicalPpaPacket::ROLE_ORGANIZER,
            'event_participation_scope_id' => null,
            'title' => 'Collaborative Campus Fest',
            'location_type' => 'on-campus',
            'location_details' => 'Gymnasium',
            'start_date' => today()->addDays(20),
            'end_date' => today()->addDays(20),
        ]);

        // Invited participant scope
        $scope = $this->createParticipantScope($event, $partnerOrg);

        $service = app(ParticipantPpaPacketService::class);

        // Save draft through participant scope
        $packet = $service->saveDraft($scope, $partnerAdmin, [
            'proponent_name' => 'Partner Leader',
            'proponent_position' => 'President',
            'delegate_count' => 25,
            'source_of_fund' => 'Partner Funds',
            'budget_cost' => 1500,
            'budget_breakdown' => [['item' => 'Snacks', 'amount' => 1500]],
        ], UploadedFile::fake()->create('evidence.pdf', 256, 'application/pdf'));

        $this->assertNotNull($packet);
        $this->assertSame(CanonicalPpaPacket::ROLE_PARTICIPANT, $packet->activity_role);
        $this->assertSame($scope->id, $packet->event_participation_scope_id);
        $this->assertSame($event->id, $packet->event_id);

        // Organizer packet remains intact and unchanged
        $eventFresh = $event->fresh(['canonicalPpaPacket', 'participantPpaPackets']);
        $this->assertSame($organizerPacket->id, $eventFresh->canonicalPpaPacket->id);
        $this->assertSame(CanonicalPpaPacket::ROLE_ORGANIZER, $eventFresh->canonicalPpaPacket->activity_role);
        $this->assertCount(1, $eventFresh->participantPpaPackets);
        $this->assertSame($packet->id, $eventFresh->participantPpaPackets->first()->id);

        // Participant submit
        $submittedPacket = $service->submitPacket($scope->fresh(), $partnerAdmin);
        $this->assertSame(EventParticipationScope::PPA_SUBMITTED, $scope->fresh()->ppa_status);
        $this->assertSame(CanonicalPpaPacket::ROLE_PARTICIPANT, $submittedPacket->activity_role);
    }

    public function test_organizer_scope_cannot_create_or_submit_participant_packet_through_participant_endpoints(): void
    {
        $h = $this->createHierarchy();
        $organizerAdmin = $this->createAdmin($h['organizer']);

        $event = $this->createEvent($h['organizer'], 'Organizer Own Event');
        CanonicalPpaPacket::factory()->for($event)->create([
            'activity_role' => CanonicalPpaPacket::ROLE_ORGANIZER,
            'event_participation_scope_id' => null,
            'title' => 'Organizer Own Event',
        ]);

        // Organizer attendance scope
        $organizerScope = app(ParticipationScopeService::class)->ensureOrganizerScope($event);
        $this->assertTrue($organizerScope->isOrganizer());
        $this->assertFalse($organizerScope->isParticipant());

        $service = app(ParticipantPpaPacketService::class);

        // 1. Service getOrCreatePacket on organizer scope throws DomainException
        try {
            $service->getOrCreatePacket($organizerScope, $organizerAdmin);
            $this->fail('Expected DomainException was not thrown on getOrCreatePacket for organizer scope.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Participant packets can only be created for invited participant scopes.', $e->getMessage());
        }

        // 2. Service saveDraft on organizer scope throws DomainException
        try {
            $service->saveDraft($organizerScope, $organizerAdmin, ['proponent_name' => 'Invalid']);
            $this->fail('Expected DomainException was not thrown on saveDraft for organizer scope.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Participant packets can only be created for invited participant scopes.', $e->getMessage());
        }

        // 3. Service submitPacket on organizer scope throws DomainException
        try {
            $service->submitPacket($organizerScope, $organizerAdmin);
            $this->fail('Expected DomainException was not thrown on submitPacket for organizer scope.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Participant packets can only be created for invited participant scopes.', $e->getMessage());
        }

        // 4. Web draft endpoint on organizer scope returns 422
        $this->actingAs($organizerAdmin)
            ->post(route('dashboard.events.participant-scopes.draft', [$event, $organizerScope]), [
                'proponent_name' => 'Organizer Attempt',
            ])
            ->assertStatus(422);

        // 5. Web submit endpoint on organizer scope returns 422
        $this->actingAs($organizerAdmin)
            ->post(route('dashboard.events.participant-scopes.submit', [$event, $organizerScope]), [
                'proponent_name' => 'Organizer Attempt',
            ])
            ->assertStatus(422);
    }

    public function test_submission_guard_rejects_mismatched_scope_and_role_combinations(): void
    {
        $h = $this->createHierarchy();
        $event = $this->createEvent($h['organizer'], 'Submission Guard Test');
        $guard = app(EventProposalSubmissionGuard::class);

        // Organizer packet with participant role is rejected by submission guard
        $packet = CanonicalPpaPacket::factory()->for($event)->create([
            'activity_role' => CanonicalPpaPacket::ROLE_PARTICIPANT,
            'event_participation_scope_id' => null,
            'location_type' => 'on-campus',
            'location_details' => 'Gym',
        ]);

        $missing = $guard->missingRequirements($event->fresh());
        $this->assertContains('valid organizer activity role', $missing);

        // Participant packet missing participation scope is rejected by participant submission guard
        $participantMissing = $guard->missingParticipantRequirements($packet);
        $this->assertContains('associated participation scope', $participantMissing);
    }

    // --- Helpers ---

    private function baseWebPayload(array $overrides = []): array
    {
        $date = today()->addDays(10)->toDateString();

        return array_merge([
            'title' => 'Standard PPA Proposal',
            'proposal_category' => 'activity',
            'activity_role' => CanonicalPpaPacket::ROLE_ORGANIZER,
            'start_date' => $date,
            'end_date' => $date,
            'location_type' => 'off-campus',
            'location_details' => 'Kabacan Municipal Gym',
            'implementing_office' => 'PSITS',
            'target_participants' => 'All students',
            'source_of_fund' => 'Organization fund',
            'budget_cost' => 2000,
            'resolution_number' => 'RES-2026-001',
            'proponent_name' => 'Officer Name',
            'proponent_position' => 'Secretary',
            'proponent_organization' => 'PSITS',
            'proponent_email' => 'officer@usm.edu.ph',
            'proponent_contact_number' => '09170000000',
            'rationale' => 'Sample rationale for testing.',
            'general_objective' => 'Sample objective.',
            'specific_objectives' => "Objective 1\nObjective 2",
            'methodology' => 'Sample methodology.',
            'major_activities' => "Activity 1\nActivity 2",
            'expected_output' => 'Expected output.',
            'event_days' => [[
                'date' => $date,
                'start_time' => '08:00',
                'end_time' => '12:00',
            ]],
            'evaluation_questions' => $this->customEvaluationQuestions(),
        ], $overrides);
    }

    private function baseApiPayload(array $overrides = []): array
    {
        $date = today()->addDays(10)->toDateString();

        return array_merge([
            'title' => 'API PPA Proposal',
            'proposal_category' => 'activity',
            'activity_role' => CanonicalPpaPacket::ROLE_ORGANIZER,
            'start_date' => $date,
            'end_date' => $date,
            'location_type' => 'off-campus',
            'location_details' => 'Kabacan Municipal Gym',
            'event_days' => [[
                'date' => $date,
                'start_time' => '08:00',
                'end_time' => '12:00',
            ]],
            'evaluation_questions' => $this->customEvaluationQuestions(),
        ], $overrides);
    }

    private function customEvaluationQuestions(): array
    {
        return [
            ['id' => 'q1', 'label' => 'Quality of content', 'description' => 'Rate the overall content.'],
        ];
    }

    /**
     * @return array{university: University, college: College, department: Department, organizer: Organization}
     */
    private function createHierarchy(): array
    {
        $university = University::create([
            'name' => 'University of Southern Mindanao',
            'domain' => 'usm.edu.ph',
        ]);

        $college = College::create([
            'university_id' => $university->id,
            'name' => 'College of Information Technology',
            'code' => 'CIT-'.uniqid(),
        ]);

        $department = Department::create([
            'college_id' => $college->id,
            'name' => 'Computer Science Department',
            'code' => 'CS-'.uniqid(),
            'status' => 'active',
        ]);

        $organizer = Organization::create([
            'college_id' => $college->id,
            'department_id' => $department->id,
            'name' => 'Lead Organizer Society',
            'acronym' => 'LOS-'.rand(10, 99),
            'type' => 'society',
            'status' => 'active',
        ]);

        return [
            'university' => $university,
            'college' => $college,
            'department' => $department,
            'organizer' => $organizer,
        ];
    }

    private function createOrganization(College $college, string $name, string $acronym): Organization
    {
        return Organization::create([
            'college_id' => $college->id,
            'department_id' => null,
            'name' => $name.' '.uniqid(),
            'acronym' => $acronym.rand(10, 99),
            'type' => 'society',
            'status' => 'active',
        ]);
    }

    private function createEvent(Organization $organization, string $title = 'Test Event'): Event
    {
        return Event::create([
            'school_year_id' => $this->schoolYear->id,
            'organization_id' => $organization->id,
            'title' => $title.' '.uniqid(),
            'proposal_category' => 'activity',
            'location_type' => 'off-campus',
            'location_details' => 'Main Campus Ground',
            'status' => Event::STATUS_DRAFT,
            'start_date' => today()->addDays(5),
            'end_date' => today()->addDays(5),
        ]);
    }

    private function createAdmin(Organization $organization, string $roleName = 'Society Admin'): User
    {
        $user = User::create([
            'name' => 'Admin '.uniqid(),
            'email' => 'admin-'.uniqid().'@usm.edu.ph',
            'password' => bcrypt('password123'),
        ]);

        app(AdminAssignmentService::class)->createAssignment(
            user: $user,
            roleName: $roleName,
            organization: $organization,
            college: $organization->college,
            academicYear: '2026-2027',
            termStart: '2026-07-01',
            termEnd: '2027-06-30',
            positionTitle: 'Leader',
            approvedBy: $user,
            isPrimaryAdmin: true,
        );

        return $user;
    }

    private function createParticipantScope(Event $event, Organization $organization): EventParticipationScope
    {
        return EventParticipationScope::create([
            'event_id' => $event->id,
            'school_year_id' => $this->schoolYear->id,
            'parent_scope_id' => app(ParticipationScopeService::class)->ensureOrganizerScope($event)->id,
            'participation_role' => EventParticipationScope::ROLE_PARTICIPANT,
            'participant_type' => 'society',
            'organization_id' => $organization->id,
            'college_id' => $organization->college_id,
            'participant_name_snapshot' => $organization->name,
            'ppa_required' => true,
            'ppa_status' => EventParticipationScope::PPA_DRAFT,
            'capture_mode' => 'self_scanning',
            'analytics_rollup_enabled' => true,
            'status' => EventParticipationScope::STATUS_ACTIVE,
        ]);
    }
}
