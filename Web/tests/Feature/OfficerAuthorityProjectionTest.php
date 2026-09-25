<?php

namespace Tests\Feature;

use App\Models\AdminAssignment;
use App\Models\College;
use App\Models\Event;
use App\Models\OfficerChangeRequest;
use App\Models\Organization;
use App\Models\OrganizationOfficer;
use App\Models\OrganizationOfficerRoster;
use App\Models\PendingStudentLink;
use App\Models\SchoolYear;
use App\Models\User;
use App\Services\OfficerAuthorityProjectionService;
use App\Support\EventTenantScope;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class OfficerAuthorityProjectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        Route::middleware(['web', 'auth', 'tenant.scope:create-proposals'])
            ->get('/_test/authority-scope/{organization}', fn () => response()->json(['allowed' => true]));

        Route::middleware(['web', 'auth'])
            ->get('/_test/event-tenant-scope', function () {
                $query = Event::query();
                EventTenantScope::apply($query, request()->user());

                return response()->json(['ids' => $query->pluck('id')->all()]);
            });

        Route::middleware(['web', 'auth'])
            ->get('/_test/event-tenant-scope/{event}', function (Event $event) {
                EventTenantScope::authorize($event, request()->user());

                return response()->json(['allowed' => true]);
            });
    }

    public function test_verification_projects_president_and_secretary_authority_for_the_active_school_year(): void
    {
        [$organization, $schoolYear, $roster, $president, $secretary, $adviser, $presidentOfficer, $secretaryOfficer] = $this->submittedRoster();
        $outgoingPresident = User::factory()->create(['user_type' => 'student']);
        $outgoingPresident->assignRole('Society Admin');
        $legacyAssignment = $this->createActiveAssignment($outgoingPresident, $organization, $schoolYear);
        $legacyAssignment->update([
            'position_title' => 'President',
            'is_primary_admin' => true,
        ]);

        $this->actingAs($adviser)
            ->postJson(route('dashboard.officer-rosters.verify', [
                'organization' => $organization,
                'roster' => $roster,
            ]))
            ->assertOk();

        $presidentAssignment = AdminAssignment::query()
            ->where('source_type', AdminAssignment::SOURCE_VERIFIED_OFFICER)
            ->where('source_id', $presidentOfficer->id)
            ->sole();
        $secretaryAssignment = AdminAssignment::query()
            ->where('source_type', AdminAssignment::SOURCE_VERIFIED_OFFICER)
            ->where('source_id', $secretaryOfficer->id)
            ->sole();

        $this->assertSame($president->id, $presidentAssignment->user_id);
        $this->assertSame($secretary->id, $secretaryAssignment->user_id);
        $this->assertSame($organization->id, $presidentAssignment->organization_id);
        $this->assertSame($organization->id, $secretaryAssignment->organization_id);
        $this->assertSame($schoolYear->id, $presidentAssignment->school_year_id);
        $this->assertSame($schoolYear->id, $secretaryAssignment->school_year_id);
        $this->assertTrue($presidentAssignment->is_primary_admin);
        $this->assertFalse($secretaryAssignment->is_primary_admin);
        $this->assertSame(AdminAssignment::STATUS_ACTIVE, $presidentAssignment->status);
        $this->assertSame(AdminAssignment::STATUS_ACTIVE, $secretaryAssignment->status);
        $this->assertTrue($president->fresh()->hasRole('Society Admin'));
        $this->assertTrue($secretary->fresh()->hasRole('Society Admin'));
        $this->assertSame(AdminAssignment::STATUS_EXPIRED, $legacyAssignment->fresh()->status);
        $this->assertFalse($outgoingPresident->fresh()->hasRole('Society Admin'));

        $presidentOfficer->update(['position_title' => 'Organization President']);
        app(OfficerAuthorityProjectionService::class)->synchronize($roster, $adviser);

        $this->assertSame(2, AdminAssignment::query()
            ->where('source_type', AdminAssignment::SOURCE_VERIFIED_OFFICER)
            ->where('organization_id', $organization->id)
            ->count());
        $this->assertSame('Organization President', $presidentAssignment->fresh()->position_title);
    }

    public function test_approved_secretary_replacement_revokes_old_projection_and_grants_the_replacement(): void
    {
        [$organization, $schoolYear, $roster, , $secretary, $adviser, , $secretaryOfficer] = $this->submittedRoster();

        $this->actingAs($adviser)
            ->postJson(route('dashboard.officer-rosters.verify', [
                'organization' => $organization,
                'roster' => $roster,
            ]))
            ->assertOk();

        $replacement = User::factory()->create(['user_type' => 'student']);
        $changeRequest = OfficerChangeRequest::create([
            'organization_id' => $organization->id,
            'officer_roster_id' => $roster->id,
            'old_officer_id' => $secretaryOfficer->id,
            'new_user_id' => $replacement->id,
            'request_type' => OfficerChangeRequest::TYPE_REPLACEMENT,
            'position_key' => OrganizationOfficer::POSITION_SECRETARY,
            'position_title' => 'Secretary',
            'status' => OfficerChangeRequest::STATUS_PENDING,
            'reason' => 'Secretary resigned and the elected replacement was verified.',
            'requested_by' => $secretary->id,
            'requested_at' => now(),
        ]);

        $this->actingAs($adviser)
            ->postJson(route('dashboard.officer-change-requests.approve', [
                'organization' => $organization,
                'changeRequest' => $changeRequest,
            ]))
            ->assertOk();

        $oldAssignment = AdminAssignment::query()
            ->where('source_type', AdminAssignment::SOURCE_VERIFIED_OFFICER)
            ->where('source_id', $secretaryOfficer->id)
            ->sole();
        $newOfficer = $changeRequest->fresh()->createdOfficer;
        $newAssignment = AdminAssignment::query()
            ->where('source_type', AdminAssignment::SOURCE_VERIFIED_OFFICER)
            ->where('source_id', $newOfficer->id)
            ->sole();

        $this->assertSame(AdminAssignment::STATUS_REVOKED, $oldAssignment->status);
        $this->assertSame($adviser->id, $oldAssignment->revoked_by);
        $this->assertNotNull($oldAssignment->revoked_at);
        $this->assertSame(AdminAssignment::STATUS_ACTIVE, $newAssignment->status);
        $this->assertSame($replacement->id, $newAssignment->user_id);
        $this->assertSame($schoolYear->id, $newAssignment->school_year_id);
        $this->assertFalse($secretary->fresh()->hasRole('Society Admin'));
        $this->assertTrue($replacement->fresh()->hasRole('Society Admin'));
    }

    public function test_approved_secretary_revocation_removes_projection_without_replacement(): void
    {
        [$organization, , $roster, $president, $secretary, $adviser, , $secretaryOfficer] = $this->submittedRoster();

        $this->actingAs($adviser)
            ->postJson(route('dashboard.officer-rosters.verify', [
                'organization' => $organization,
                'roster' => $roster,
            ]))
            ->assertOk();

        $changeRequest = OfficerChangeRequest::create([
            'organization_id' => $organization->id,
            'officer_roster_id' => $roster->id,
            'old_officer_id' => $secretaryOfficer->id,
            'new_user_id' => null,
            'request_type' => OfficerChangeRequest::TYPE_REVOCATION,
            'position_key' => OrganizationOfficer::POSITION_SECRETARY,
            'position_title' => 'Secretary',
            'status' => OfficerChangeRequest::STATUS_PENDING,
            'reason' => 'Secretary authority was revoked by the adviser.',
            'requested_by' => $president->id,
            'requested_at' => now(),
        ]);

        $this->actingAs($adviser)
            ->postJson(route('dashboard.officer-change-requests.approve', [
                'organization' => $organization,
                'changeRequest' => $changeRequest,
            ]))
            ->assertOk();

        $oldAssignment = AdminAssignment::query()
            ->where('source_type', AdminAssignment::SOURCE_VERIFIED_OFFICER)
            ->where('source_id', $secretaryOfficer->id)
            ->sole();

        $this->assertSame(AdminAssignment::STATUS_REVOKED, $oldAssignment->status);
        $this->assertSame(OrganizationOfficer::STATUS_REVOKED, $secretaryOfficer->fresh()->status);
        $this->assertNull($changeRequest->fresh()->created_officer_id);
        $this->assertFalse($secretary->fresh()->hasRole('Society Admin'));
        $this->assertSame(1, AdminAssignment::query()
            ->where('source_type', AdminAssignment::SOURCE_VERIFIED_OFFICER)
            ->where('organization_id', $organization->id)
            ->where('status', AdminAssignment::STATUS_ACTIVE)
            ->count());
    }

    public function test_tenant_middleware_requires_and_enforces_explicit_context_for_multiple_organizations(): void
    {
        $college = College::factory()->create();
        $firstOrganization = Organization::create([
            'college_id' => $college->id,
            'name' => 'First Society',
            'acronym' => 'FIRST',
            'type' => 'society',
            'status' => 'active',
        ]);
        $secondOrganization = Organization::create([
            'college_id' => $college->id,
            'name' => 'Second Society',
            'acronym' => 'SECOND',
            'type' => 'society',
            'status' => 'active',
        ]);
        $schoolYear = SchoolYear::factory()->create([
            'status' => SchoolYear::STATUS_ACTIVE,
            'activated_at' => now(),
        ]);
        $officer = User::factory()->create(['user_type' => 'student']);
        $officer->assignRole('Society Admin');

        $firstAssignment = $this->createActiveAssignment($officer, $firstOrganization, $schoolYear);
        $secondAssignment = $this->createActiveAssignment($officer, $secondOrganization, $schoolYear);

        $this->actingAs($officer)
            ->getJson("/_test/authority-scope/{$firstOrganization->id}")
            ->assertForbidden();

        $this->actingAs($officer)
            ->withHeader('X-Authority-Context', $firstAssignment->id)
            ->getJson("/_test/authority-scope/{$firstOrganization->id}")
            ->assertOk()
            ->assertJson(['allowed' => true]);

        $this->actingAs($officer)
            ->withHeader('X-Authority-Context', $firstAssignment->id)
            ->getJson("/_test/authority-scope/{$secondOrganization->id}")
            ->assertForbidden();

        $this->actingAs($officer)
            ->withHeader('X-Authority-Context', $secondAssignment->id)
            ->getJson("/_test/authority-scope/{$secondOrganization->id}")
            ->assertOk();

        $firstLsg = Organization::create([
            'college_id' => $college->id,
            'name' => 'First LSG',
            'acronym' => 'FLSG',
            'type' => 'lsg',
            'status' => 'active',
        ]);
        $secondLsg = Organization::create([
            'college_id' => $college->id,
            'name' => 'Second LSG',
            'acronym' => 'SLSG',
            'type' => 'lsg',
            'status' => 'active',
        ]);
        $lsgOfficer = User::factory()->create(['user_type' => 'student']);
        $lsgOfficer->assignRole('LSG Admin');
        $lsgAssignment = $this->createActiveAssignment(
            $lsgOfficer,
            $firstLsg,
            $schoolYear,
            roleName: 'LSG Admin',
            sourceId: Str::uuid()->toString()
        );

        $this->actingAs($lsgOfficer)
            ->withHeader('X-Authority-Context', $lsgAssignment->id)
            ->getJson("/_test/authority-scope/{$secondLsg->id}")
            ->assertForbidden();
    }

    public function test_admin_role_without_active_projection_must_select_authority_context(): void
    {
        $officer = User::factory()->create(['user_type' => 'student']);
        $officer->assignRole('Society Admin');

        $this->actingAs($officer)
            ->get(route('dashboard'))
            ->assertRedirect(route('authority-context.index'));

        $this->get(route('authority-context.index'))
            ->assertOk()
            ->assertSee('No active administrative authority is assigned to this account.');
    }

    public function test_api_lists_use_selected_exact_authority_context_not_broad_role_scope(): void
    {
        $college = College::factory()->create();
        $firstOrganization = Organization::create([
            'college_id' => $college->id,
            'name' => 'First API LSG',
            'acronym' => 'FAPI',
            'type' => 'lsg',
            'status' => 'active',
        ]);
        $secondOrganization = Organization::create([
            'college_id' => $college->id,
            'name' => 'Second API LSG',
            'acronym' => 'SAPI',
            'type' => 'lsg',
            'status' => 'active',
        ]);
        $schoolYear = SchoolYear::factory()->create([
            'status' => SchoolYear::STATUS_ACTIVE,
            'activated_at' => now(),
        ]);
        $officer = User::factory()->create(['user_type' => 'student']);
        $officer->assignRole('LSG Admin');
        $assignment = $this->createActiveAssignment(
            $officer,
            $firstOrganization,
            $schoolYear,
            roleName: 'LSG Admin',
            sourceId: Str::uuid()->toString()
        );

        $visibleEvent = Event::factory()->create([
            'organization_id' => $firstOrganization->id,
            'title' => 'Visible Exact Context Event',
        ]);
        $hiddenEvent = Event::factory()->create([
            'organization_id' => $secondOrganization->id,
            'title' => 'Hidden Same College Event',
        ]);
        PendingStudentLink::create([
            'event_id' => Event::factory()->create(['organization_id' => $firstOrganization->id])->id,
            'organization_id' => $firstOrganization->id,
            'qr_code_value' => 'VISIBLE-QR',
            'status' => 'pending',
        ]);
        PendingStudentLink::create([
            'event_id' => Event::factory()->create(['organization_id' => $secondOrganization->id])->id,
            'organization_id' => $secondOrganization->id,
            'qr_code_value' => 'HIDDEN-QR',
            'status' => 'pending',
        ]);

        $this->actingAs($officer, 'sanctum')
            ->withHeader('X-Authority-Context', $assignment->id)
            ->getJson('/api/v1/events')
            ->assertOk()
            ->assertJsonFragment(['title' => 'Visible Exact Context Event'])
            ->assertJsonMissing(['title' => 'Hidden Same College Event']);

        $this->actingAs($officer, 'sanctum')
            ->withHeader('X-Authority-Context', $assignment->id)
            ->getJson('/api/v1/pending-student-links')
            ->assertOk()
            ->assertJsonFragment(['qr_code_value' => 'VISIBLE-QR'])
            ->assertJsonMissing(['qr_code_value' => 'HIDDEN-QR']);

        $eventScopeResponse = $this->actingAs($officer)
            ->withHeader('X-Authority-Context', $assignment->id)
            ->getJson('/_test/event-tenant-scope')
            ->assertOk();

        $this->assertContains($visibleEvent->id, $eventScopeResponse->json('ids'));
        $this->assertNotContains($hiddenEvent->id, $eventScopeResponse->json('ids'));

        $this->actingAs($officer)
            ->withHeader('X-Authority-Context', $assignment->id)
            ->getJson("/_test/event-tenant-scope/{$visibleEvent->id}")
            ->assertOk();

        $this->actingAs($officer)
            ->withHeader('X-Authority-Context', $assignment->id)
            ->getJson("/_test/event-tenant-scope/{$hiddenEvent->id}")
            ->assertForbidden();
    }

    public function test_multi_organization_officer_selects_an_owned_web_authority_context(): void
    {
        $college = College::factory()->create();
        $firstOrganization = Organization::create([
            'college_id' => $college->id,
            'name' => 'First Web Society',
            'acronym' => 'FWS',
            'type' => 'society',
            'status' => 'active',
        ]);
        $secondOrganization = Organization::create([
            'college_id' => $college->id,
            'name' => 'Second Web Society',
            'acronym' => 'SWS',
            'type' => 'society',
            'status' => 'active',
        ]);
        $schoolYear = SchoolYear::factory()->create([
            'status' => SchoolYear::STATUS_ACTIVE,
            'activated_at' => now(),
        ]);
        $officer = User::factory()->create(['user_type' => 'student']);
        $officer->assignRole('Society Admin');

        $this->createActiveAssignment($officer, $firstOrganization, $schoolYear);
        $secondAssignment = $this->createActiveAssignment($officer, $secondOrganization, $schoolYear);

        $this->actingAs($officer)
            ->get(route('dashboard'))
            ->assertRedirect(route('authority-context.index'));

        $this->actingAs($officer)
            ->post(route('authority-context.store'), [
                'assignment_id' => $secondAssignment->id,
            ])
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('active_authority_assignment_id', $secondAssignment->id);

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Second Web Society');

        $otherOfficer = User::factory()->create(['user_type' => 'student']);
        $otherAssignment = $this->createActiveAssignment($otherOfficer, $firstOrganization, $schoolYear);

        $this->postJson(route('authority-context.store'), [
            'assignment_id' => $otherAssignment->id,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('assignment_id')
            ->assertSessionHas('active_authority_assignment_id', $secondAssignment->id);
    }

    private function createActiveAssignment(
        User $user,
        Organization $organization,
        SchoolYear $schoolYear,
        string $roleName = 'Society Admin',
        ?string $sourceId = null
    ): AdminAssignment {
        return AdminAssignment::create([
            'user_id' => $user->id,
            'role_name' => $roleName,
            'organization_id' => $organization->id,
            'college_id' => $organization->college_id,
            'school_year_id' => $schoolYear->id,
            'academic_year' => $schoolYear->label,
            'position_title' => 'Officer',
            'source_type' => $sourceId ? AdminAssignment::SOURCE_VERIFIED_OFFICER : null,
            'source_id' => $sourceId,
            'status' => AdminAssignment::STATUS_ACTIVE,
            'is_primary_admin' => false,
            'approved_at' => now(),
        ]);
    }

    /**
     * @return array{Organization, SchoolYear, OrganizationOfficerRoster, User, User, User, OrganizationOfficer, OrganizationOfficer}
     */
    private function submittedRoster(): array
    {
        $college = College::factory()->create();
        $organization = Organization::create([
            'college_id' => $college->id,
            'name' => 'Information Technology Society',
            'acronym' => 'ITS',
            'type' => 'society',
            'status' => 'active',
        ]);
        $schoolYear = SchoolYear::factory()->create([
            'status' => SchoolYear::STATUS_ACTIVE,
            'starts_on' => now()->startOfYear()->toDateString(),
            'ends_on' => now()->endOfYear()->toDateString(),
            'activated_at' => now(),
        ]);
        $president = User::factory()->create(['user_type' => 'student']);
        $secretary = User::factory()->create(['user_type' => 'student']);
        $adviser = User::factory()->create(['user_type' => 'faculty_or_staff']);
        $adviser->assignRole('Faculty');

        $roster = OrganizationOfficerRoster::create([
            'organization_id' => $organization->id,
            'school_year_id' => $schoolYear->id,
            'status' => OrganizationOfficerRoster::STATUS_SUBMITTED,
            'attestation_text' => 'Official roster submission attestation.',
            'submitted_by' => $president->id,
            'submitted_at' => now(),
            'created_by' => $president->id,
        ]);

        $presidentOfficer = OrganizationOfficer::create([
            'officer_roster_id' => $roster->id,
            'user_id' => $president->id,
            'position_key' => OrganizationOfficer::POSITION_PRESIDENT,
            'position_title' => 'President',
        ]);
        $secretaryOfficer = OrganizationOfficer::create([
            'officer_roster_id' => $roster->id,
            'user_id' => $secretary->id,
            'position_key' => OrganizationOfficer::POSITION_SECRETARY,
            'position_title' => 'Secretary',
        ]);
        OrganizationOfficer::create([
            'officer_roster_id' => $roster->id,
            'user_id' => $adviser->id,
            'position_key' => OrganizationOfficer::POSITION_ADVISER,
            'position_title' => 'Adviser',
        ]);

        return [$organization, $schoolYear, $roster, $president, $secretary, $adviser, $presidentOfficer, $secretaryOfficer];
    }
}
