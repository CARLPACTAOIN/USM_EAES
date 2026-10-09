<?php

namespace Tests\Feature;

use App\Models\CanonicalPpaPacket;
use App\Models\College;
use App\Models\Event;
use App\Models\Organization;
use App\Models\SchoolYear;
use App\Models\University;
use App\Models\User;
use App\Models\Venue;
use App\Services\AdminAssignmentService;
use App\Services\EventProposalSubmissionGuard;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrganizerProposalDraftTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organizer;

    private User $organizerAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $university = University::create([
            'name' => 'University of Southern Mindanao',
            'domain' => 'usm.edu.ph',
        ]);

        $college = College::create([
            'university_id' => $university->id,
            'name' => 'College of Information Technology',
            'code' => 'CIT',
        ]);

        SchoolYear::create([
            'label' => '2026-2027',
            'starts_on' => '2026-06-01',
            'ends_on' => '2027-03-31',
            'status' => SchoolYear::STATUS_ACTIVE,
            'activated_at' => now(),
        ]);

        $this->organizer = Organization::create([
            'college_id' => $college->id,
            'name' => 'Information Systems Society',
            'acronym' => 'ISS',
            'type' => 'society',
            'status' => 'active',
        ]);

        $this->organizerAdmin = $this->createOrganizerAdmin($this->organizer, 'organizer');
    }

    public function test_an_organizer_can_start_an_empty_draft_without_creating_downstream_records(): void
    {
        $this->actingAs($this->organizerAdmin)
            ->post(route('dashboard.proposal-drafts.start'))
            ->assertRedirect();

        $event = Event::firstOrFail();
        $packet = $event->canonicalPpaPacket;

        $this->assertSame($this->organizer->id, $event->organization_id);
        $this->assertSame(Event::STATUS_DRAFT, $event->status);
        $this->assertNull($event->title);
        $this->assertNull($event->start_date);
        $this->assertNull($event->end_date);
        $this->assertNull($event->location_type);
        $this->assertNull($event->proposal_document_path);
        $this->assertNull($event->venue_confirmation_document_path);
        $this->assertNotNull($event->school_year_id);
        $this->assertNotNull($event->proponent_user_id);
        $this->assertNotNull($packet);
        $this->assertSame(CanonicalPpaPacket::ROLE_ORGANIZER, $packet->activity_role);
        $this->assertSame($this->organizerAdmin->id, $packet->prepared_by_user_id);
        $this->assertSame($this->organizer->name, $packet->proponent_organization);
        $this->assertDatabaseCount('event_days', 0);
        $this->assertDatabaseCount('evaluation_form_versions', 0);
        $this->assertDatabaseCount('event_participation_scopes', 0);
        $this->assertDatabaseCount('proposal_workflow_runs', 0);
        $this->assertDatabaseCount('proposal_workflow_tasks', 0);
    }

    public function test_autosave_uses_patch_semantics_allows_clearing_and_is_idempotent(): void
    {
        $event = $this->startDraft();

        $payload = [
            'title' => 'Systems Congress',
            'canonical_packet' => [
                'rationale' => 'Bring student systems leaders together.',
            ],
        ];

        $this->actingAs($this->organizerAdmin)
            ->patch(route('dashboard.proposal-drafts.save', $event), $payload)
            ->assertRedirect(route('dashboard.proposal-drafts.edit', $event));

        $this->actingAs($this->organizerAdmin)
            ->patch(route('dashboard.proposal-drafts.save', $event), $payload)
            ->assertRedirect(route('dashboard.proposal-drafts.edit', $event));

        $event->refresh();
        $packet = $event->canonicalPpaPacket()->firstOrFail();
        $this->assertSame('Systems Congress', $event->title);
        $this->assertSame('Bring student systems leaders together.', $packet->rationale);
        $this->assertDatabaseCount('canonical_ppa_packets', 1);

        $this->actingAs($this->organizerAdmin)
            ->patch(route('dashboard.proposal-drafts.save', $event), [
                'title' => '',
                'canonical_packet' => ['rationale' => ''],
            ])
            ->assertRedirect(route('dashboard.proposal-drafts.edit', $event));

        $event->refresh();
        $packet->refresh();
        $this->assertNull($event->title);
        $this->assertNull($packet->rationale);
        $this->assertDatabaseCount('event_days', 0);
        $this->assertDatabaseCount('evaluation_form_versions', 0);
    }

    public function test_draft_editor_can_be_resumed_by_its_organizer_but_not_another_organizer_or_osa(): void
    {
        $event = $this->startDraft();
        $otherOrganizer = Organization::create([
            'college_id' => $this->organizer->college_id,
            'name' => 'Computer Science Society',
            'acronym' => 'CSS',
            'type' => 'society',
            'status' => 'active',
        ]);
        $otherAdmin = $this->createOrganizerAdmin($otherOrganizer, 'other');
        $osa = User::factory()->create();
        $osa->assignRole('Super Admin (OSA)');

        $this->actingAs($this->organizerAdmin)
            ->get(route('dashboard.proposal-drafts.edit', $event))
            ->assertOk()
            ->assertSee('Proposal draft')
            ->assertSee('Saved just now');

        $this->actingAs($this->organizerAdmin)
            ->get(route('dashboard.events'))
            ->assertOk()
            ->assertSee('Continue draft')
            ->assertSee(route('dashboard.proposal-drafts.edit', $event), false);

        $this->actingAs($otherAdmin)
            ->get(route('dashboard.proposal-drafts.edit', $event))
            ->assertForbidden();

        $this->actingAs($osa)
            ->patch(route('dashboard.proposal-drafts.save', $event), ['title' => 'Attempted change'])
            ->assertForbidden();

        $this->assertNull($event->fresh()->title);
    }

    public function test_guests_cannot_start_view_or_autosave_an_organizer_draft(): void
    {
        $event = $this->startDraft();
        $this->app['auth']->guard('web')->logout();

        $this->post(route('dashboard.proposal-drafts.start'))
            ->assertRedirect(route('login'));
        $this->get(route('dashboard.proposal-drafts.edit', $event))
            ->assertRedirect(route('login'));
        $this->patch(route('dashboard.proposal-drafts.save', $event), ['title' => 'Attempted change'])
            ->assertRedirect(route('login'));

        $this->postJson('/api/v1/proposal-drafts')->assertUnauthorized();
        $this->getJson('/api/v1/proposal-drafts/'.$event->id)->assertUnauthorized();
        $this->patchJson('/api/v1/proposal-drafts/'.$event->id, ['title' => 'Attempted change'])
            ->assertUnauthorized();
    }

    public function test_an_organizer_cannot_change_the_packet_role(): void
    {
        $event = $this->startDraft();

        $this->actingAs($this->organizerAdmin)
            ->patch(route('dashboard.proposal-drafts.save', $event), [
                'canonical_packet' => ['activity_role' => CanonicalPpaPacket::ROLE_PARTICIPANT],
            ])
            ->assertSessionHasErrors('canonical_packet.activity_role');

        $this->assertSame(CanonicalPpaPacket::ROLE_ORGANIZER, $event->canonicalPpaPacket()->value('activity_role'));
    }

    public function test_api_can_create_and_patch_a_permissive_organizer_draft(): void
    {
        Sanctum::actingAs($this->organizerAdmin);

        $response = $this->postJson('/api/v1/proposal-drafts')
            ->assertCreated()
            ->assertJsonPath('event.status', Event::STATUS_DRAFT)
            ->assertJsonPath('event.title', null);

        $eventId = $response->json('event.id');

        $this->patchJson('/api/v1/proposal-drafts/'.$eventId, [
            'proposal_category' => 'activity',
            'location_type' => 'online',
            'event_days' => [],
            'evaluation_questions' => [],
            'canonical_packet' => ['beneficiaries' => 'All IS students'],
        ])
            ->assertOk()
            ->assertJsonPath('event.proposal_category', 'activity')
            ->assertJsonPath('event.location_type', 'online')
            ->assertJsonPath('event.canonical_ppa_packet.beneficiaries', 'All IS students');

        $this->getJson('/api/v1/proposal-drafts/'.$eventId)
            ->assertOk()
            ->assertJsonPath('event.id', $eventId);

        $event = Event::findOrFail($eventId);
        $this->assertDatabaseCount('event_days', 0);
        $this->assertDatabaseCount('evaluation_form_versions', 0);
        $this->assertDatabaseCount('event_participation_scopes', 0);
    }

    public function test_variant_b_workspace_renders_six_sections_readiness_and_governance_context(): void
    {
        $event = $this->startDraft();

        $response = $this->actingAs($this->organizerAdmin)
            ->get(route('dashboard.proposal-drafts.edit', $event))
            ->assertOk();

        // Check 6-section tabs
        $response->assertSee('Basics')
            ->assertSee('Schedule &amp; venue', false)
            ->assertSee('Program &amp; beneficiaries', false)
            ->assertSee('Budget')
            ->assertSee('Evaluation &amp; files', false)
            ->assertSee('Review &amp; submit', false);

        // Check prefilled read-only governance context
        $response->assertSee($this->organizer->name)
            ->assertSee($this->organizerAdmin->name)
            ->assertSee('College of Information Technology')
            ->assertSee('2026-2027');

        // Check review drawer and status banner
        $response->assertSee('Packet state')
            ->assertSee('Needs attention')
            ->assertSee('Authoritative completion')
            ->assertSee('submitAfterFlushing($event)', false);
    }

    public function test_ajax_autosave_returns_json_with_authoritative_readiness_payload(): void
    {
        $event = $this->startDraft();

        $response = $this->actingAs($this->organizerAdmin)
            ->patchJson(route('dashboard.proposal-drafts.save', $event), [
                'title' => 'Leadership Seminar',
                'proposal_category' => 'activity',
                'canonical_packet' => [
                    'rationale' => 'Enhance leadership capabilities.',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('event.title', 'Leadership Seminar')
            ->assertJsonPath('packet.rationale', 'Enhance leadership capabilities.');

        $readiness = $response->json('readiness');
        $this->assertIsArray($readiness);
        $this->assertArrayHasKey('score', $readiness);
        $this->assertArrayHasKey('total_checks', $readiness);
        $this->assertArrayHasKey('passed_checks', $readiness);
        $this->assertArrayHasKey('missing_count', $readiness);
        $this->assertArrayHasKey('submittable', $readiness);
        $this->assertArrayHasKey('issues', $readiness);
        $this->assertArrayHasKey('grouped_issues', $readiness);
        $this->assertFalse($readiness['submittable']);
        $this->assertGreaterThan(0, $readiness['missing_count']);

        $positionIssue = collect($readiness['issues'])->firstWhere('field', 'proponent_position');
        $this->assertNotNull($positionIssue);
        $this->assertSame('basics', $positionIssue['section']);
        $this->assertSame('draft-proponent-position', $positionIssue['target_id']);
        $this->assertContains($positionIssue, $readiness['grouped_issues']['basics']);
    }

    public function test_venue_branching_on_campus_off_campus_and_online(): void
    {
        $venue = Venue::factory()->create([
            'is_active' => true,
            'managing_office' => 'Campus Facilities Office',
        ]);
        $event = $this->startDraft();

        // 1. On-campus with active catalog venue
        $this->actingAs($this->organizerAdmin)
            ->patchJson(route('dashboard.proposal-drafts.save', $event), [
                'location_type' => 'on-campus',
                'venue_id' => $venue->id,
            ])
            ->assertOk();

        $event->refresh();
        $this->assertSame('on-campus', $event->location_type);
        $this->assertSame($venue->id, $event->venue_id);

        // 2. Switch to off-campus: venue_id is cleared, confirmation fields persisted
        $this->actingAs($this->organizerAdmin)
            ->patchJson(route('dashboard.proposal-drafts.save', $event), [
                'location_type' => 'off-campus',
                'location_details' => 'Southpoint Convention Center, Kabacan',
                'venue_confirmation_office' => 'City Tourism Office',
                'venue_confirmation_date' => '2026-09-15',
                'venue_confirmation_reference_number' => 'CTO-2026-09',
            ])
            ->assertOk();

        $event->refresh();
        $this->assertSame('off-campus', $event->location_type);
        $this->assertNull($event->venue_id);
        $this->assertSame('Southpoint Convention Center, Kabacan', $event->location_details);
        $this->assertSame('City Tourism Office', $event->venue_confirmation_office);
        $this->assertSame('2026-09-15', $event->venue_confirmation_date?->format('Y-m-d'));
        $this->assertSame('CTO-2026-09', $event->venue_confirmation_reference_number);

        // 3. Switch to online: venue_id and confirmation fields cleared, platform details persisted
        $this->actingAs($this->organizerAdmin)
            ->patchJson(route('dashboard.proposal-drafts.save', $event), [
                'location_type' => 'online',
                'location_details' => 'Google Meet: meet.google.com/abc-defg-hij',
            ])
            ->assertOk();

        $event->refresh();
        $this->assertSame('online', $event->location_type);
        $this->assertNull($event->venue_id);
        $this->assertNull($event->venue_confirmation_office);
        $this->assertNull($event->venue_confirmation_date);
        $this->assertNull($event->venue_confirmation_reference_number);
        $this->assertSame('Google Meet: meet.google.com/abc-defg-hij', $event->location_details);

        // 4. Switch back to on-campus: confirmation details cleared
        $this->actingAs($this->organizerAdmin)
            ->patchJson(route('dashboard.proposal-drafts.save', $event), [
                'location_type' => 'on-campus',
                'venue_id' => $venue->id,
            ])
            ->assertOk();

        $event->refresh();
        $this->assertSame('on-campus', $event->location_type);
        $this->assertSame($venue->id, $event->venue_id);
        $this->assertNull($event->venue_confirmation_office);
    }

    public function test_supporting_and_proposal_document_uploads_and_clearing(): void
    {
        Storage::fake();
        $event = $this->startDraft();

        $proposalPdf = UploadedFile::fake()->create('activity_proposal.pdf', 300, 'application/pdf');
        $supportingPdf = UploadedFile::fake()->create('endorsement_letter.pdf', 200, 'application/pdf');

        $this->actingAs($this->organizerAdmin)
            ->patch(route('dashboard.proposal-drafts.save', $event), [
                'proposal_document' => $proposalPdf,
                'supporting_document' => $supportingPdf,
            ])
            ->assertRedirect(route('dashboard.proposal-drafts.edit', $event));

        $event->refresh();
        $packet = $event->canonicalPpaPacket;
        $this->assertNotNull($event->proposal_document_path);
        Storage::assertExists($event->proposal_document_path);

        $this->assertDatabaseHas('canonical_ppa_attachments', [
            'canonical_ppa_packet_id' => $packet->id,
            'attachment_type' => 'proposal',
            'original_name' => 'activity_proposal.pdf',
        ]);
        $this->assertDatabaseHas('canonical_ppa_attachments', [
            'canonical_ppa_packet_id' => $packet->id,
            'attachment_type' => 'supporting',
            'original_name' => 'endorsement_letter.pdf',
        ]);

        $this->actingAs($this->organizerAdmin)
            ->patch(route('dashboard.proposal-drafts.save', $event), [
                'clear_proposal_document' => false,
            ])
            ->assertRedirect(route('dashboard.proposal-drafts.edit', $event));

        $event->refresh();
        $this->assertNotNull($event->proposal_document_path);

        // Now clear proposal document
        $this->actingAs($this->organizerAdmin)
            ->patch(route('dashboard.proposal-drafts.save', $event), [
                'clear_proposal_document' => true,
            ])
            ->assertRedirect(route('dashboard.proposal-drafts.edit', $event));

        $event->refresh();
        $this->assertNull($event->proposal_document_path);
        $this->assertDatabaseMissing('canonical_ppa_attachments', [
            'canonical_ppa_packet_id' => $packet->id,
            'attachment_type' => 'proposal',
        ]);
        // Supporting document remains
        $this->assertDatabaseHas('canonical_ppa_attachments', [
            'canonical_ppa_packet_id' => $packet->id,
            'attachment_type' => 'supporting',
        ]);
    }

    public function test_full_packet_completion_reaches_authoritative_100_percent(): void
    {
        $venue = Venue::factory()->create(['is_active' => true]);
        $event = $this->startDraft();

        $fullPayload = [
            'title' => 'Annual Technology Summit 2026',
            'proposal_category' => 'activity',
            'location_type' => 'on-campus',
            'venue_id' => $venue->id,
            'start_date' => '2026-11-10',
            'end_date' => '2026-11-11',
            'canonical_packet' => [
                'proponent_position' => 'President',
                'proponent_contact_number' => '09123456789',
                'implementing_office' => 'Information Systems Society',
                'rationale' => 'Provide IT students with modern industry exposure.',
                'general_objective' => 'Upskill students in cloud architectures.',
                'specific_objectives' => [
                    'Deliver 4 technical workshops',
                    'Host hackathon with 10 teams',
                ],
                'methodology' => 'Interactive hands-on coding and keynote lectures.',
                'expected_output' => 'Completed capstone prototypes.',
                'beneficiaries' => 'Undergraduate IT students',
                'delegate_count' => 150,
                'source_of_fund' => 'Student Development Fund',
                'budget_cost' => 15000.00,
                'resolution_number' => 'RES-2026-08',
                'budget_breakdown' => [
                    ['item' => 'Speaker Honorarium', 'amount' => 5000.00],
                    ['item' => 'Certificates & Materials', 'amount' => 10000.00],
                ],
                'major_activities' => [
                    'Day 1 Keynote',
                    'Day 2 Hackathon',
                ],
                'program_activities' => [
                    ['time' => '08:00 AM', 'activity' => 'Registration', 'persons_involved' => 'Secretariat'],
                    ['time' => '09:00 AM', 'activity' => 'Opening Ceremony', 'persons_involved' => 'Organizers'],
                ],
            ],
        ];

        $response = $this->actingAs($this->organizerAdmin)
            ->patchJson(route('dashboard.proposal-drafts.save', $event), $fullPayload)
            ->assertOk();

        $readiness = $response->json('readiness');
        $this->assertSame(100, $readiness['score']);
        $this->assertSame(0, $readiness['missing_count']);
        $this->assertTrue($readiness['submittable']);
        $this->assertEmpty($readiness['issues']);

        // Guard evaluates same
        $guard = app(EventProposalSubmissionGuard::class);
        $evaluation = $guard->evaluate($event->fresh());
        $this->assertTrue($evaluation['submittable']);
        $this->assertSame(100, $evaluation['score']);
    }

    public function test_organizer_draft_mutation_does_not_mutate_participant_packets_or_create_workflow_runs(): void
    {
        $event = $this->startDraft();

        $this->actingAs($this->organizerAdmin)
            ->patchJson(route('dashboard.proposal-drafts.save', $event), [
                'title' => 'Isolation Check Event',
                'canonical_packet' => [
                    'beneficiaries' => 'Testing scope isolation',
                ],
            ])
            ->assertOk();

        $this->assertDatabaseCount('proposal_workflow_runs', 0);
        $this->assertDatabaseCount('proposal_workflow_tasks', 0);
        $this->assertDatabaseCount('event_days', 0);
        $this->assertDatabaseCount('evaluation_form_versions', 0);
        $this->assertDatabaseCount('event_participation_scopes', 0);

        // Assert organizer packet role is organizer
        $this->assertSame(CanonicalPpaPacket::ROLE_ORGANIZER, $event->canonicalPpaPacket()->value('activity_role'));
    }

    private function startDraft(): Event
    {
        $this->actingAs($this->organizerAdmin)
            ->post(route('dashboard.proposal-drafts.start'))
            ->assertRedirect();

        return Event::sole();
    }

    private function createOrganizerAdmin(Organization $organization, string $prefix): User
    {
        $user = User::factory()->create([
            'name' => ucfirst($prefix).' Organizer',
            'email' => $prefix.'-'.uniqid().'@usm.edu.ph',
            'organization_id' => $organization->id,
            'college_id' => $organization->college_id,
        ]);

        app(AdminAssignmentService::class)->createAssignment(
            user: $user,
            roleName: 'Society Admin',
            organization: $organization,
            college: $organization->college,
            academicYear: '2026-2027',
            termStart: '2026-06-01',
            termEnd: '2027-03-31',
            positionTitle: 'Secretary',
            approvedBy: $user,
            isPrimaryAdmin: true,
        );

        return $user;
    }
}
