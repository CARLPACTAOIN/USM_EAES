<?php

namespace Tests\Feature;

use App\Models\CanonicalPpaPacket;
use App\Models\College;
use App\Models\Event;
use App\Models\Organization;
use App\Models\SchoolYear;
use App\Models\University;
use App\Models\User;
use App\Services\AdminAssignmentService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        $this->actingAs($otherAdmin)
            ->get(route('dashboard.proposal-drafts.edit', $event))
            ->assertForbidden();

        $this->actingAs($osa)
            ->patch(route('dashboard.proposal-drafts.save', $event), ['title' => 'Attempted change'])
            ->assertForbidden();

        $this->assertNull($event->fresh()->title);
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
