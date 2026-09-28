<?php

namespace Tests\Feature;

use App\Livewire\Dashboard\EventParticipantScopes;
use App\Models\CanonicalPpaAttachment;
use App\Models\CanonicalPpaPacket;
use App\Models\College;
use App\Models\Department;
use App\Models\Event;
use App\Models\EventParticipationScope;
use App\Models\Organization;
use App\Models\Program;
use App\Models\SchoolYear;
use App\Models\University;
use App\Models\User;
use App\Models\Venue;
use App\Services\AdminAssignmentService;
use App\Services\ParticipantPpaPacketService;
use App\Services\ParticipationScopeService;
use Database\Seeders\RolePermissionSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class ParticipantCanonicalPpaPacketTest extends TestCase
{
    use RefreshDatabase;

    protected SchoolYear $schoolYear;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Storage::fake();

        $this->schoolYear = SchoolYear::factory()->create([
            'label' => '2026-2027',
            'starts_on' => '2026-07-01',
            'ends_on' => '2027-06-30',
            'status' => SchoolYear::STATUS_ACTIVE,
        ]);
    }

    public function test_participating_organization_completes_canonical_ppa_packet_with_activity_role_participant(): void
    {
        $h = $this->createHierarchy();
        $organizerAdmin = $this->createAdmin($h['organizer']);
        $partnerOrg = $this->createOrganization($h['college'], 'Partner Society', 'PAR');
        $partnerAdmin = $this->createAdmin($partnerOrg);

        $event = $this->createEvent($h['organizer'], 'Campus Tech Forum');
        $organizerPacket = CanonicalPpaPacket::factory()->for($event)->create([
            'activity_role' => CanonicalPpaPacket::ROLE_ORGANIZER,
            'title' => 'Campus Tech Forum',
            'location_type' => 'on-campus',
            'location_details' => 'University Gym',
            'start_date' => today()->addDays(10),
            'end_date' => today()->addDays(10),
        ]);

        $scope = $this->createParticipantScope($event, $partnerOrg);

        $service = app(ParticipantPpaPacketService::class);
        $packet = $service->getOrCreatePacket($scope, $partnerAdmin);

        $this->assertNotNull($packet);
        $this->assertSame(CanonicalPpaPacket::ROLE_PARTICIPANT, $packet->activity_role);
        $this->assertSame($scope->id, $packet->event_participation_scope_id);
        $this->assertSame($event->id, $packet->event_id);
        $this->assertSame($scope->displayName(), $packet->proponent_organization);

        // Organizer packet remains distinct
        $this->assertSame($organizerPacket->id, $event->fresh()->canonicalPpaPacket->id);
        $this->assertSame(CanonicalPpaPacket::ROLE_ORGANIZER, $event->fresh()->canonicalPpaPacket->activity_role);
    }

    public function test_participant_packet_inherits_parent_event_approved_venue_dates_and_permit_context_as_read_only_data(): void
    {
        $h = $this->createHierarchy();
        $partnerOrg = $this->createOrganization($h['college'], 'Inheriting Partner', 'INH');
        $partnerAdmin = $this->createAdmin($partnerOrg);

        $venue = Venue::factory()->create([
            'name' => 'University Auditorium',
        ]);

        $event = $this->createEvent($h['organizer'], 'University Leadership Summit');
        $event->update([
            'venue_id' => $venue->id,
            'location_type' => 'on-campus',
            'location_details' => 'Auditorium Main Stage',
            'start_date' => today()->addDays(15),
            'end_date' => today()->addDays(16),
            'permit_version' => 1,
            'venue_confirmation_office' => 'OSA Venue Office',
            'venue_confirmation_date' => today()->subDays(2),
        ]);

        CanonicalPpaPacket::factory()->for($event)->create([
            'activity_role' => CanonicalPpaPacket::ROLE_ORGANIZER,
            'title' => 'University Leadership Summit',
            'venue_id' => $venue->id,
            'location_type' => 'on-campus',
            'location_details' => 'Auditorium Main Stage',
            'start_date' => today()->addDays(15),
            'end_date' => today()->addDays(16),
            'venue_confirmation_office' => 'OSA Venue Office',
            'venue_confirmation_date' => today()->subDays(2),
        ]);

        $scope = $this->createParticipantScope($event, $partnerOrg);
        $service = app(ParticipantPpaPacketService::class);

        // Even if caller attempts to override venue or dates, parent context is strictly enforced
        $bogusVenue = Venue::factory()->create(['name' => 'Rogue Venue']);
        $packet = $service->saveDraft($scope, $partnerAdmin, [
            'proponent_name' => 'Partner Leader',
            'proponent_position' => 'President',
            'delegate_count' => 25,
            'venue_id' => $bogusVenue->id,
            'location_type' => 'off-campus',
            'location_details' => 'External Hotel',
            'start_date' => today()->addDays(100),
            'end_date' => today()->addDays(101),
        ]);

        $this->assertSame($venue->id, $packet->venue_id);
        $this->assertSame('on-campus', $packet->location_type);
        $this->assertSame('Auditorium Main Stage', $packet->location_details);
        $this->assertSame($event->start_date->toDateString(), $packet->start_date->toDateString());
        $this->assertSame($event->end_date->toDateString(), $packet->end_date->toDateString());
        $this->assertSame('OSA Venue Office', $packet->venue_confirmation_office);
        $this->assertSame(CanonicalPpaPacket::ROLE_PARTICIPANT, $packet->activity_role);
    }

    public function test_participant_draft_saves_remain_permissive(): void
    {
        $h = $this->createHierarchy();
        $partnerOrg = $this->createOrganization($h['college'], 'Draft Partner', 'DRF');
        $partnerAdmin = $this->createAdmin($partnerOrg);

        $event = $this->createEvent($h['organizer'], 'Annual College Meet');
        CanonicalPpaPacket::factory()->for($event)->create();
        $scope = $this->createParticipantScope($event, $partnerOrg);

        // Saving a partial draft with missing delegate count, budget, or evidence succeeds
        $this->actingAs($partnerAdmin)
            ->post(route('dashboard.events.participant-scopes.draft', [$event->id, $scope->id]), [
                'proponent_name' => 'In-Progress Coordinator',
                'rationale' => 'Drafting participation justification.',
            ])
            ->assertSessionHas('success', 'Participant PPA draft saved.');

        $packet = $scope->fresh()->canonicalPpaPacket;
        $this->assertNotNull($packet);
        $this->assertSame('In-Progress Coordinator', $packet->proponent_name);
        $this->assertSame('Drafting participation justification.', $packet->rationale);
        $this->assertSame(EventParticipationScope::PPA_DRAFT, $scope->fresh()->ppa_status);
        $this->assertNull($scope->fresh()->submitted_at);
    }

    public function test_participant_submission_validates_participant_specific_required_fields(): void
    {
        $h = $this->createHierarchy();
        $partnerOrg = $this->createOrganization($h['college'], 'Strict Partner', 'STR');
        $partnerAdmin = $this->createAdmin($partnerOrg);

        $event = $this->createEvent($h['organizer'], 'Inter-Society Congress');
        CanonicalPpaPacket::factory()->for($event)->create();
        $scope = $this->createParticipantScope($event, $partnerOrg);

        $service = app(ParticipantPpaPacketService::class);

        // 1. Missing supporting checklist evidence fails
        try {
            $service->submitPacket($scope, $partnerAdmin, [
                'proponent_name' => 'Valid Name',
                'proponent_position' => 'President',
                'proponent_organization' => $partnerOrg->name,
                'proponent_email' => 'partner@usm.edu.ph',
                'delegate_count' => 10,
                'source_of_fund' => 'Org funds',
                'budget_cost' => 0,
            ]);
            $this->fail('Expected submission to fail for missing supporting checklist evidence.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('supporting checklist evidence', $e->getMessage());
        }

        // 2. Missing delegate count fails
        try {
            $service->submitPacket($scope, $partnerAdmin, [
                'proponent_name' => 'Valid Name',
                'proponent_position' => 'President',
                'proponent_organization' => $partnerOrg->name,
                'proponent_email' => 'partner@usm.edu.ph',
                'delegate_count' => null,
                'source_of_fund' => 'Org funds',
                'budget_cost' => 0,
            ], UploadedFile::fake()->create('evidence.pdf', 100));
            $this->fail('Expected submission to fail for missing delegate count.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('delegate count', $e->getMessage());
        }

        // 3. Delegate count < 1 fails
        try {
            $service->submitPacket($scope, $partnerAdmin, [
                'proponent_name' => 'Valid Name',
                'proponent_position' => 'President',
                'proponent_organization' => $partnerOrg->name,
                'proponent_email' => 'partner@usm.edu.ph',
                'delegate_count' => 0,
                'source_of_fund' => 'Org funds',
                'budget_cost' => 0,
            ]);
            $this->fail('Expected submission to fail for zero delegate count.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('delegate count', $e->getMessage());
        }

        // 4. Budget cost > 0 but missing breakdown fails
        try {
            $service->submitPacket($scope, $partnerAdmin, [
                'proponent_name' => 'Valid Name',
                'proponent_position' => 'President',
                'proponent_organization' => $partnerOrg->name,
                'proponent_email' => 'partner@usm.edu.ph',
                'delegate_count' => 10,
                'source_of_fund' => 'Org funds',
                'budget_cost' => 1500,
                'budget_breakdown' => [],
            ]);
            $this->fail('Expected submission to fail for incomplete budget breakdown.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('budget breakdown', $e->getMessage());
        }

        // 5. Valid submission with all required fields succeeds
        $packet = $service->submitPacket($scope, $partnerAdmin, [
            'proponent_name' => 'Valid Name',
            'proponent_position' => 'President',
            'proponent_organization' => $partnerOrg->name,
            'proponent_email' => 'partner@usm.edu.ph',
            'delegate_count' => 15,
            'source_of_fund' => 'Membership Dues',
            'budget_cost' => 2000,
            'budget_breakdown' => [
                ['item' => 'Delegates Transportation', 'amount' => 1200],
                ['item' => 'Materials and Snacks', 'amount' => 800],
            ],
        ], UploadedFile::fake()->create('delegates-endorsement.pdf', 100));

        $this->assertSame(EventParticipationScope::PPA_SUBMITTED, $scope->fresh()->ppa_status);
        $this->assertSame(15, $packet->delegate_count);
        $this->assertSame(2000.0, (float) $packet->budget_cost);
        $this->assertSame('delegates-endorsement.pdf', $packet->supporting_document_original_name);
        $this->assertNotNull($scope->fresh()->submitted_at);
        $this->assertSame($partnerAdmin->id, $scope->fresh()->submitted_by);
    }

    public function test_single_entry_participant_submission_prevents_duplicate_routes(): void
    {
        $h = $this->createHierarchy();
        $partnerOrg = $this->createOrganization($h['college'], 'Single Submission Partner', 'SSP');
        $partnerAdmin = $this->createAdmin($partnerOrg);

        $event = $this->createEvent($h['organizer'], 'University Science Expo');
        CanonicalPpaPacket::factory()->for($event)->create();
        $scope = $this->createParticipantScope($event, $partnerOrg);

        $service = app(ParticipantPpaPacketService::class);
        $service->submitPacket($scope, $partnerAdmin, [
            'proponent_name' => 'Single Coordinator',
            'proponent_position' => 'Lead',
            'proponent_organization' => $partnerOrg->name,
            'proponent_email' => 'lead@usm.edu.ph',
            'delegate_count' => 8,
            'source_of_fund' => 'Department allocation',
            'budget_cost' => 0,
        ], UploadedFile::fake()->create('official-roster.pdf', 100));

        $this->assertSame(EventParticipationScope::PPA_SUBMITTED, $scope->fresh()->ppa_status);

        // Attempting to re-submit while already submitted is prevented
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Only draft or revision-requested participant PPAs can be submitted.');

        $service->submitPacket($scope, $partnerAdmin, [
            'delegate_count' => 12,
        ]);
    }

    public function test_web_routes_for_draft_submission_preview_and_evidence(): void
    {
        $h = $this->createHierarchy();
        $osa = $this->createAdmin($h['organizer'], 'Super Admin (OSA)');
        $partnerOrg = $this->createOrganization($h['college'], 'Web Route Partner', 'WRP');
        $partnerAdmin = $this->createAdmin($partnerOrg);

        $event = $this->createEvent($h['organizer'], 'Inter-College Academic Derby');
        CanonicalPpaPacket::factory()->for($event)->create([
            'title' => 'Inter-College Academic Derby',
            'location_type' => 'on-campus',
            'location_details' => 'Auditorium Hall B',
        ]);
        $scope = $this->createParticipantScope($event, $partnerOrg);

        // 1. Post draft
        $this->actingAs($partnerAdmin)
            ->post(route('dashboard.events.participant-scopes.draft', [$event->id, $scope->id]), [
                'proponent_name' => 'Web Proponent',
                'proponent_position' => 'Secretary',
                'delegate_count' => 12,
                'source_of_fund' => 'Student activity fee',
                'budget_cost' => 500,
                'budget_breakdown' => [['item' => 'Badges', 'amount' => 500]],
                'supporting_document' => UploadedFile::fake()->create('checklist-evidence.pdf', 128, 'application/pdf'),
            ])
            ->assertSessionHas('success', 'Participant PPA draft saved.');

        $this->assertSame(12, $scope->fresh()->canonicalPpaPacket->delegate_count);
        $this->assertDatabaseHas('canonical_ppa_attachments', [
            'canonical_ppa_packet_id' => $scope->fresh()->canonicalPpaPacket->id,
            'attachment_type' => CanonicalPpaAttachment::TYPE_SUPPORTING,
            'original_name' => 'checklist-evidence.pdf',
        ]);

        // 2. Submit packet
        $this->actingAs($partnerAdmin)
            ->post(route('dashboard.events.participant-scopes.submit', [$event->id, $scope->id]))
            ->assertSessionHas('success', 'Participant PPA submitted for OSA review.');

        $this->assertSame(EventParticipationScope::PPA_SUBMITTED, $scope->fresh()->ppa_status);

        // 3. Preview packet
        $this->actingAs($partnerAdmin)
            ->get(route('dashboard.events.participant-scopes.preview', [$event->id, $scope->id]))
            ->assertOk()
            ->assertSee('Participant')
            ->assertSee('Web Proponent')
            ->assertSee('Delegate count')
            ->assertSee('12')
            ->assertSee('Auditorium Hall B');

        // 4. Download evidence
        $this->actingAs($partnerAdmin)
            ->get(route('dashboard.events.participant-scopes.evidence', [$event->id, $scope->id]))
            ->assertOk();

        // 5. OSA can also preview and download evidence
        $this->actingAs($osa)
            ->get(route('dashboard.events.participant-scopes.preview', [$event->id, $scope->id]))
            ->assertOk();

        $this->actingAs($osa)
            ->get(route('dashboard.events.participant-scopes.evidence', [$event->id, $scope->id]))
            ->assertOk();
    }

    public function test_organizer_packet_attachment_downloads_are_scoped_to_the_event_organization(): void
    {
        $h = $this->createHierarchy();
        $organizerAdmin = $this->createAdmin($h['organizer']);
        $otherOrg = $this->createOrganization($h['college'], 'Other Attachment Org', 'OAO');
        $otherAdmin = $this->createAdmin($otherOrg);
        $event = $this->createEvent($h['organizer'], 'Attachment Privacy Event');
        $packet = CanonicalPpaPacket::factory()->for($event)->create();
        Storage::put('canonical-ppa/proposal.pdf', 'private proposal');
        $attachment = CanonicalPpaAttachment::factory()->for($packet, 'packet')->create([
            'attachment_type' => CanonicalPpaAttachment::TYPE_PROPOSAL,
            'path' => 'canonical-ppa/proposal.pdf',
            'original_name' => 'proposal.pdf',
        ]);

        $this->actingAs($organizerAdmin)
            ->get(route('dashboard.events.ppa-attachments', [$event, $attachment]))
            ->assertOk();

        $this->actingAs($otherAdmin)
            ->get(route('dashboard.events.ppa-attachments', [$event, $attachment]))
            ->assertForbidden();

        $this->assertArrayNotHasKey('path', $packet->fresh(['attachments'])->toArray()['attachments'][0] ?? []);
    }

    public function test_livewire_component_saves_draft_and_submits_canonical_packet(): void
    {
        $h = $this->createHierarchy();
        $partnerOrg = $this->createOrganization($h['college'], 'Livewire Packet Partner', 'LPP');
        $partnerAdmin = $this->createAdmin($partnerOrg);

        $event = $this->createEvent($h['organizer'], 'Livewire Canonical Event');
        CanonicalPpaPacket::factory()->for($event)->create();
        $scope = $this->createParticipantScope($event, $partnerOrg);

        Livewire::actingAs($partnerAdmin)
            ->test(EventParticipantScopes::class, [
                'eventId' => $event->id,
                'isParticipatingWorkspace' => true,
            ])
            ->set("packetForms.{$scope->id}.proponent_name", 'Livewire Lead')
            ->set("packetForms.{$scope->id}.proponent_position", 'Officer')
            ->set("packetForms.{$scope->id}.delegate_count", 20)
            ->set("packetForms.{$scope->id}.source_of_fund", 'Society Funds')
            ->set("packetForms.{$scope->id}.budget_cost", 1000)
            ->set("budgetBreakdowns.{$scope->id}", [
                ['item' => 'Delegates kits', 'amount' => 1000],
            ])
            ->set("supportingDocuments.{$scope->id}", UploadedFile::fake()->create('livewire-evidence.pdf', 100))
            ->call('submitParticipantPacket', $scope->id)
            ->assertSet('notice', 'Participant PPA submitted for OSA review.')
            ->assertDispatched('notifications-updated');

        $scope->refresh();
        $this->assertSame(EventParticipationScope::PPA_SUBMITTED, $scope->ppa_status);
        $packet = $scope->canonicalPpaPacket;
        $this->assertNotNull($packet);
        $this->assertSame('Livewire Lead', $packet->proponent_name);
        $this->assertSame(20, $packet->delegate_count);
        $this->assertSame(CanonicalPpaPacket::ROLE_PARTICIPANT, $packet->activity_role);
    }

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

        $program = Program::create([
            'department_id' => $department->id,
            'college_id' => $college->id,
            'name' => 'BS Computer Science',
            'code' => 'BSCS-'.uniqid(),
            'status' => 'active',
        ]);

        $organizer = Organization::create([
            'college_id' => $college->id,
            'department_id' => $department->id,
            'name' => 'CS Society Organizer',
            'acronym' => 'CSSO-'.uniqid(),
            'type' => 'society',
            'status' => 'active',
        ]);
        $organizer->programs()->attach($program->id);

        return [
            'college' => $college,
            'department' => $department,
            'program' => $program,
            'organizer' => $organizer,
        ];
    }

    private function createOrganization(College $college, string $name, string $acronym): Organization
    {
        return Organization::create([
            'college_id' => $college->id,
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
            'location_type' => 'on-campus',
            'location_details' => 'Main Campus Ground',
            'status' => Event::STATUS_SUBMITTED,
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

        if ($roleName === 'Super Admin (OSA)') {
            $user->assignRole('Super Admin (OSA)');

            return $user;
        }

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
