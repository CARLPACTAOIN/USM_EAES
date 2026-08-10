<?php

namespace Tests\Feature;

use App\Models\College;
use App\Models\Event;
use App\Models\EventParticipationScope;
use App\Models\Organization;
use App\Models\Program;
use App\Models\University;
use App\Models\User;
use App\Services\AdminAssignmentService;
use App\Services\ParticipationScopeService;
use Database\Seeders\RolePermissionSeeder;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class ParticipantScopeConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_active_scope_unique_indexes_reject_duplicate_identities(): void
    {
        $hierarchy = $this->createHierarchy();
        $event = $this->createEvent($hierarchy['organizer']);
        $service = app(ParticipationScopeService::class);
        $organizerScope = $service->ensureOrganizerScope($event);
        $partner = $this->createOrganization($hierarchy['college'], 'Partner Society', 'PARTNER');
        $linkedEvent = $this->createEvent($partner, 'Linked Event');
        $otherPartner = $this->createOrganization($hierarchy['college'], 'Other Partner Society', 'OTHER');
        $linkedDuplicatePartner = $this->createOrganization($hierarchy['college'], 'Linked Duplicate Partner', 'LINKDUP');

        $organizationScope = $this->createParticipantScope($event, $partner);
        $collegeScope = $this->createParticipantScope($event, null, $hierarchy['college']);
        $linkedScope = $this->createParticipantScope($event, $otherPartner, null, $linkedEvent);

        foreach ([
            fn (): EventParticipationScope => EventParticipationScope::create($this->scopeAttributes($event, [
                'participation_role' => EventParticipationScope::ROLE_ORGANIZER,
                'organization_id' => $hierarchy['organizer']->id,
                'college_id' => $hierarchy['college']->id,
            ])),
            fn (): EventParticipationScope => EventParticipationScope::create($this->scopeAttributes($event, [
                'organization_id' => $organizationScope->organization_id,
                'college_id' => $organizationScope->college_id,
            ])),
            fn (): EventParticipationScope => EventParticipationScope::create($this->scopeAttributes($event, [
                'participant_type' => 'college',
                'organization_id' => null,
                'college_id' => $collegeScope->college_id,
            ])),
            fn (): EventParticipationScope => EventParticipationScope::create($this->scopeAttributes($event, [
                'organization_id' => $linkedDuplicatePartner->id,
                'college_id' => $linkedDuplicatePartner->college_id,
                'linked_event_id' => $linkedScope->linked_event_id,
            ])),
        ] as $duplicateInsert) {
            try {
                $duplicateInsert();
                $this->fail('Expected the active participation-scope unique index to reject a duplicate identity.');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_inactive_and_revoked_scope_history_does_not_block_new_active_scopes(): void
    {
        $hierarchy = $this->createHierarchy();
        $event = $this->createEvent($hierarchy['organizer']);
        $service = app(ParticipationScopeService::class);
        $partner = $this->createOrganization($hierarchy['college'], 'Historical Partner', 'HIST');
        $linkedEvent = $this->createEvent($partner, 'Historical Linked Event');

        $organizerScope = $service->ensureOrganizerScope($event);
        $organizerScope->update(['status' => EventParticipationScope::STATUS_REVOKED]);
        $this->assertNotSame($organizerScope->id, $service->ensureOrganizerScope($event)->id);

        $organizationScope = $this->createParticipantScope($event, $partner);
        $organizationScope->update(['status' => EventParticipationScope::STATUS_INACTIVE]);
        $replacementOrganizationScope = $this->createParticipantScope($event, $partner);
        $this->assertModelExists($replacementOrganizationScope);
        $replacementOrganizationScope->update(['status' => EventParticipationScope::STATUS_REVOKED]);

        $collegeScope = $this->createParticipantScope($event, null, $hierarchy['college']);
        $collegeScope->update(['status' => EventParticipationScope::STATUS_REVOKED]);
        $replacementCollegeScope = $this->createParticipantScope($event, null, $hierarchy['college']);
        $this->assertModelExists($replacementCollegeScope);
        $replacementCollegeScope->update(['status' => EventParticipationScope::STATUS_INACTIVE]);

        $linkedScope = $this->createParticipantScope($event, $partner, null, $linkedEvent);
        $linkedScope->update(['status' => EventParticipationScope::STATUS_INACTIVE]);

        $this->assertModelExists($this->createParticipantScope($event, $partner, null, $linkedEvent));
    }

    public function test_participant_scope_creation_is_idempotent_and_preserves_first_writer_configuration(): void
    {
        $hierarchy = $this->createHierarchy();
        $event = $this->createEvent($hierarchy['organizer'], 'Idempotent Audience Event');
        $partner = $this->createOrganization($hierarchy['college'], 'Idempotent Partner', 'IDEMP');
        $recipient = $this->createAdmin($partner);
        $service = app(ParticipationScopeService::class);

        $first = $service->createParticipantScopes(
            event: $event,
            audienceMode: 'organization',
            organizationIds: [$partner->id],
            collegeIds: [],
            captureMode: 'central_gate',
            ppaRequired: false,
            analyticsRollupEnabled: true,
        );
        $second = $service->createParticipantScopes(
            event: $event,
            audienceMode: 'organization',
            organizationIds: [$partner->id],
            collegeIds: [],
            captureMode: 'self_scanning',
            ppaRequired: true,
            analyticsRollupEnabled: false,
        );

        $scope = EventParticipationScope::query()
            ->where('event_id', $event->id)
            ->where('organization_id', $partner->id)
            ->where('participation_role', EventParticipationScope::ROLE_PARTICIPANT)
            ->firstOrFail();

        $this->assertSame(['created_count' => 1, 'skipped_organizer_count' => 0, 'skipped_existing_count' => 0], $first);
        $this->assertSame(['created_count' => 0, 'skipped_organizer_count' => 0, 'skipped_existing_count' => 1], $second);
        $this->assertFalse($scope->ppa_required);
        $this->assertSame(EventParticipationScope::PPA_NOT_REQUIRED, $scope->ppa_status);
        $this->assertSame('central_gate', $scope->capture_mode);
        $this->assertTrue($scope->analytics_rollup_enabled);
        $this->assertSame(1, $recipient->fresh()->notifications()
            ->get()
            ->filter(fn ($notification): bool => ($notification->data['kind'] ?? null) === 'participant_scope_invitation')
            ->count());
    }

    public function test_mixed_audience_batches_create_non_conflicting_scopes_and_skip_existing_ones(): void
    {
        $hierarchy = $this->createHierarchy();
        $event = $this->createEvent($hierarchy['organizer']);
        $existingPartner = $this->createOrganization($hierarchy['college'], 'Existing Partner', 'EXIST');
        $newPartner = $this->createOrganization($hierarchy['college'], 'New Partner', 'NEW');
        $service = app(ParticipationScopeService::class);

        $service->createParticipantScopes($event, 'organization', [$existingPartner->id], [], 'self_scanning', true, true);
        $result = $service->createParticipantScopes($event, 'organization', [$existingPartner->id, $newPartner->id], [], 'central_gate', false, false);

        $this->assertSame(['created_count' => 1, 'skipped_organizer_count' => 0, 'skipped_existing_count' => 1], $result);
        $this->assertSame(2, EventParticipationScope::query()
            ->where('event_id', $event->id)
            ->where('participation_role', EventParticipationScope::ROLE_PARTICIPANT)
            ->whereIn('organization_id', [$existingPartner->id, $newPartner->id])
            ->count());
    }

    public function test_linked_event_sync_reuses_manual_scope_and_rejects_another_linked_event_for_the_same_organization(): void
    {
        $hierarchy = $this->createHierarchy();
        $parentEvent = $this->createEvent($hierarchy['organizer'], 'Parent Permit');
        $partner = $this->createOrganization($hierarchy['college'], 'Linked Partner', 'LINK');
        $linkedEvent = $this->createEvent($partner, 'Linked Proposal');
        $conflictingLinkedEvent = $this->createEvent($partner, 'Conflicting Linked Proposal');
        $service = app(ParticipationScopeService::class);

        $service->createParticipantScopes($parentEvent, 'organization', [$partner->id], [], 'central_gate', false, false);
        $scope = $service->syncParticipantScopeFromLinkedEvent($parentEvent, $linkedEvent);
        $repeatedScope = $service->syncParticipantScopeFromLinkedEvent($parentEvent, $linkedEvent);

        $this->assertFalse($scope->wasRecentlyCreated);
        $this->assertSame($linkedEvent->id, $scope->linked_event_id);
        $this->assertSame($scope->id, $repeatedScope->id);
        $this->assertFalse($scope->fresh()->ppa_required);
        $this->assertSame('central_gate', $scope->fresh()->capture_mode);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('already has an active participant scope linked to another event');

        $service->syncParticipantScopeFromLinkedEvent($parentEvent, $conflictingLinkedEvent);
    }

    public function test_migration_aborts_before_creating_indexes_when_duplicate_audiences_exist(): void
    {
        $hierarchy = $this->createHierarchy();
        $event = $this->createEvent($hierarchy['organizer']);
        $service = app(ParticipationScopeService::class);
        $service->ensureOrganizerScope($event);
        $migration = require database_path('migrations/2026_08_08_145452_add_active_participation_scope_uniqueness.php');

        $migration->down();
        $duplicate = EventParticipationScope::create($this->scopeAttributes($event, [
            'participation_role' => EventParticipationScope::ROLE_ORGANIZER,
            'organization_id' => $hierarchy['organizer']->id,
            'college_id' => $hierarchy['college']->id,
        ]));

        try {
            $migration->up();
            $this->fail('Expected the migration to abort when duplicate active organizer scopes exist.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('type=organizer', $exception->getMessage());
            $this->assertModelExists($duplicate);
        } finally {
            $duplicate->delete();
            $migration->up();
        }
    }

    public function test_web_and_api_link_conflicts_return_domain_feedback_without_updating_the_event(): void
    {
        $hierarchy = $this->createHierarchy();
        $parentOrganizer = $this->createOrganization($hierarchy['college'], 'CEIT Local Student Government', 'CEIT-LSG', 'lsg');
        $parentEvent = $this->createEvent($parentOrganizer, 'Parent Permit');
        $partner = $this->createOrganization($hierarchy['college'], 'Conflicting Link Partner', 'CLP');
        $admin = $this->createAdmin($partner);
        $alreadyLinkedEvent = $this->createEvent($partner, 'Already Linked Proposal');
        $webRequestedEvent = $this->createEvent($partner, 'Web Conflict Proposal');
        $apiRequestedEvent = $this->createEvent($partner, 'API Conflict Proposal');
        $service = app(ParticipationScopeService::class);

        $service->syncParticipantScopeFromLinkedEvent($parentEvent, $alreadyLinkedEvent);

        $this->actingAs($admin)
            ->post(route('dashboard.events.link', $webRequestedEvent), ['parent_event_id' => $parentEvent->id])
            ->assertRedirect()
            ->assertSessionHas('error', 'This organization already has an active participant scope linked to another event under the parent permit.');
        $this->assertNull($webRequestedEvent->fresh()->parent_event_id);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/events/{$apiRequestedEvent->id}/link", ['parent_event_id' => $parentEvent->id])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'This organization already has an active participant scope linked to another event under the parent permit.');
        $this->assertNull($apiRequestedEvent->fresh()->parent_event_id);
    }

    /**
     * @return array{college: College, organizer: Organization}
     */
    private function createHierarchy(): array
    {
        $university = University::create(['name' => 'USM', 'domain' => 'usm.edu.ph']);
        $college = College::create(['university_id' => $university->id, 'name' => 'CEIT', 'code' => 'CEIT']);
        $program = Program::create(['college_id' => $college->id, 'name' => 'BS Information Systems', 'code' => 'BSIS']);
        $organizer = $this->createOrganization($college, 'PSITS', 'PSITS');
        $organizer->programs()->attach($program->id);

        return compact('college', 'organizer');
    }

    private function createOrganization(College $college, string $name, string $acronym, string $type = 'society'): Organization
    {
        return Organization::create([
            'college_id' => $college->id,
            'name' => $name,
            'acronym' => $acronym,
            'type' => $type,
        ]);
    }

    private function createEvent(Organization $organization, string $title = 'Participant Scope Event'): Event
    {
        return Event::create([
            'organization_id' => $organization->id,
            'title' => $title,
            'proposal_category' => 'activity',
            'status' => Event::STATUS_SUBMITTED,
            'start_date' => today()->addDays(5),
            'end_date' => today()->addDays(5),
        ]);
    }

    private function createAdmin(Organization $organization): User
    {
        $user = User::create([
            'name' => 'Participant Admin',
            'email' => 'participant-admin-'.uniqid().'@usm.edu.ph',
            'password' => bcrypt('password123'),
        ]);

        app(AdminAssignmentService::class)->createAssignment(
            user: $user,
            roleName: 'Society Admin',
            organization: $organization,
            college: $organization->college,
            academicYear: '2026-2027',
            termStart: '2026-07-01',
            termEnd: '2027-06-30',
            positionTitle: 'Participant administrator',
            approvedBy: $user,
            isPrimaryAdmin: true,
        );

        return $user;
    }

    private function createParticipantScope(
        Event $event,
        ?Organization $organization,
        ?College $college = null,
        ?Event $linkedEvent = null,
    ): EventParticipationScope {
        return EventParticipationScope::create($this->scopeAttributes($event, [
            'parent_scope_id' => app(ParticipationScopeService::class)->ensureOrganizerScope($event)->id,
            'organization_id' => $organization?->id,
            'college_id' => $college?->id ?? $organization?->college_id,
            'participant_type' => $college && ! $organization ? 'college' : 'society',
            'linked_event_id' => $linkedEvent?->id,
        ]));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function scopeAttributes(Event $event, array $overrides = []): array
    {
        return array_merge([
            'event_id' => $event->id,
            'participation_role' => EventParticipationScope::ROLE_PARTICIPANT,
            'participant_type' => 'society',
            'participant_name_snapshot' => 'Participant scope',
            'ppa_required' => true,
            'ppa_status' => EventParticipationScope::PPA_DRAFT,
            'capture_mode' => 'self_scanning',
            'analytics_rollup_enabled' => true,
            'status' => EventParticipationScope::STATUS_ACTIVE,
        ], $overrides);
    }
}
