<?php

namespace Tests\Feature;

use App\Models\AdminAssignment;
use App\Models\AuditLog;
use App\Models\College;
use App\Models\Organization;
use App\Models\OrganizationOfficer;
use App\Models\OrganizationOfficerRoster;
use App\Models\SchoolYear;
use App\Models\User;
use App\Services\AdminAssignmentService;
use App\Services\AdminScopeService;
use App\Services\OfficerAuthorityProjectionService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

class OfficerTransitionClosureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        Route::middleware(['web', 'auth', 'tenant.scope:create-proposals'])
            ->get('/_test/transition-authority-scope/{organization}', fn () => response()->json(['allowed' => true]));
    }

    public function test_closing_transition_replaces_outgoing_authority_with_verified_incoming_roster(): void
    {
        $osa = $this->createOsa();
        $organization = $this->createOrganization('ITS');
        $activeYear = $this->createActiveTransitionYear($osa);
        $incomingYear = SchoolYear::factory()->create([
            'label' => '2027-2028',
            'starts_on' => '2027-07-01',
            'ends_on' => '2028-06-30',
        ]);

        [
            ,
            $outgoingPresident,
            $outgoingSecretary,
            $outgoingPresidentOfficer,
            $outgoingSecretaryOfficer,
        ] = $this->createVerifiedRosterWithProjectedAuthority($organization, $activeYear, $osa, 'Outgoing');

        [
            ,
            $incomingPresident,
            $incomingSecretary,
            $incomingPresidentOfficer,
            $incomingSecretaryOfficer,
        ] = $this->createVerifiedRoster($organization, $incomingYear, $osa, 'Incoming');
        $futureYear = SchoolYear::factory()->create([
            'label' => '2028-2029',
            'starts_on' => '2028-07-01',
            'ends_on' => '2029-06-30',
        ]);
        [
            ,
            ,
            ,
            $futurePresidentOfficer,
            $futureSecretaryOfficer,
        ] = $this->createVerifiedRoster($organization, $futureYear, $osa, 'Future');

        $this->actingAs($osa)
            ->postJson(route('dashboard.school-years.transition.close', $activeYear), [
                'reason' => 'Incoming officers were adviser-verified and accepted.',
            ])
            ->assertOk()
            ->assertJsonPath('school_year.transition_closed_by.id', $osa->id);

        $this->assertSame(OrganizationOfficer::STATUS_REPLACED, $outgoingPresidentOfficer->fresh()->status);
        $this->assertSame($incomingPresidentOfficer->id, $outgoingPresidentOfficer->fresh()->replacement_officer_id);
        $this->assertSame(OrganizationOfficer::STATUS_REPLACED, $outgoingSecretaryOfficer->fresh()->status);
        $this->assertSame($incomingSecretaryOfficer->id, $outgoingSecretaryOfficer->fresh()->replacement_officer_id);
        $this->assertSame(AdminAssignment::STATUS_EXPIRED, $this->assignmentFor($outgoingPresidentOfficer)->fresh()->status);
        $this->assertSame(AdminAssignment::STATUS_EXPIRED, $this->assignmentFor($outgoingSecretaryOfficer)->fresh()->status);
        $this->assertSame(AdminAssignment::STATUS_ACTIVE, $this->assignmentFor($incomingPresidentOfficer)->fresh()->status);
        $this->assertSame(AdminAssignment::STATUS_ACTIVE, $this->assignmentFor($incomingSecretaryOfficer)->fresh()->status);
        $this->assertFalse($this->hasProjectedAssignment($futurePresidentOfficer));
        $this->assertFalse($this->hasProjectedAssignment($futureSecretaryOfficer));
        $this->assertTrue(app(AdminScopeService::class)->availableAssignmentsFor($outgoingPresident->fresh())->isEmpty());
        $this->assertFalse($outgoingPresident->fresh()->hasRole('Society Admin'));
        $this->assertFalse($outgoingSecretary->fresh()->hasRole('Society Admin'));
        $this->assertTrue($incomingPresident->fresh()->hasRole('Society Admin'));
        $this->assertTrue($incomingSecretary->fresh()->hasRole('Society Admin'));

        $this->actingAs($outgoingPresident)
            ->withHeader(AdminScopeService::HEADER_NAME, $this->assignmentFor($outgoingPresidentOfficer)->id)
            ->getJson("/_test/transition-authority-scope/{$organization->id}")
            ->assertForbidden();

        $closeAudit = AuditLog::query()
            ->where('target_id', $activeYear->id)
            ->where('action', 'close-school-year-transition')
            ->sole();

        $this->assertSame('Incoming officers were adviser-verified and accepted.', $closeAudit->details['reason']);
        $this->assertNotNull($closeAudit->details['closed_at']);
        $this->assertEqualsCanonicalizing(
            [$outgoingPresidentOfficer->id, $outgoingSecretaryOfficer->id],
            $closeAudit->details['expired_officer_ids']
        );
    }

    public function test_closing_transition_expires_outgoing_authority_without_verified_successor(): void
    {
        $osa = $this->createOsa();
        $organization = $this->createOrganization('NOC');
        $activeYear = $this->createActiveTransitionYear($osa);

        [
            ,
            $outgoingPresident,
            $outgoingSecretary,
            $outgoingPresidentOfficer,
            $outgoingSecretaryOfficer,
        ] = $this->createVerifiedRosterWithProjectedAuthority($organization, $activeYear, $osa, 'Outgoing');

        $this->actingAs($osa)
            ->postJson(route('dashboard.school-years.transition.close', $activeYear), [
                'reason' => '     ',
            ])
            ->assertOk();

        $this->assertSame(OrganizationOfficer::STATUS_EXPIRED, $outgoingPresidentOfficer->fresh()->status);
        $this->assertSame(OrganizationOfficer::STATUS_EXPIRED, $outgoingSecretaryOfficer->fresh()->status);
        $this->assertNull($outgoingPresidentOfficer->fresh()->replacement_officer_id);
        $this->assertNull($outgoingSecretaryOfficer->fresh()->replacement_officer_id);
        $this->assertSame(AdminAssignment::STATUS_EXPIRED, $this->assignmentFor($outgoingPresidentOfficer)->fresh()->status);
        $this->assertSame(AdminAssignment::STATUS_EXPIRED, $this->assignmentFor($outgoingSecretaryOfficer)->fresh()->status);
        $this->assertFalse($outgoingPresident->fresh()->hasRole('Society Admin'));
        $this->assertFalse($outgoingSecretary->fresh()->hasRole('Society Admin'));

        $closeAudit = AuditLog::query()
            ->where('target_id', $activeYear->id)
            ->where('action', 'close-school-year-transition')
            ->sole();

        $this->assertSame('Transition period closed.', $closeAudit->details['reason']);
    }

    public function test_closing_transition_does_not_touch_wrong_school_year_authority(): void
    {
        $osa = $this->createOsa();
        $organization = $this->createOrganization('WYR');
        $activeYear = $this->createActiveTransitionYear($osa);
        $otherYear = SchoolYear::factory()->create([
            'label' => '2025-2026',
            'starts_on' => '2025-07-01',
            'ends_on' => '2026-06-30',
        ]);

        [
            ,
            ,
            ,
            $outgoingPresidentOfficer,
            ,
        ] = $this->createVerifiedRosterWithProjectedAuthority($organization, $activeYear, $osa, 'Outgoing');

        [
            ,
            $otherPresident,
            ,
            $otherPresidentOfficer,
            ,
        ] = $this->createVerifiedRosterWithProjectedAuthority($organization, $otherYear, $osa, 'WrongYear');

        $this->actingAs($osa)
            ->postJson(route('dashboard.school-years.transition.close', $activeYear), [
                'reason' => 'Only the active transition school year should close.',
            ])
            ->assertOk();

        $this->assertSame(OrganizationOfficer::STATUS_EXPIRED, $outgoingPresidentOfficer->fresh()->status);
        $this->assertSame(OrganizationOfficer::STATUS_ACTIVE, $otherPresidentOfficer->fresh()->status);
        $this->assertSame(AdminAssignment::STATUS_ACTIVE, $this->assignmentFor($otherPresidentOfficer)->fresh()->status);
        $this->assertTrue($otherPresident->fresh()->hasRole('Society Admin'));
    }

    public function test_close_transition_rolls_back_authority_changes_when_projection_sync_fails(): void
    {
        $osa = $this->createOsa();
        $organization = $this->createOrganization('RBK');
        $activeYear = $this->createActiveTransitionYear($osa);
        $incomingYear = SchoolYear::factory()->create([
            'label' => '2027-2028',
            'starts_on' => '2027-07-01',
            'ends_on' => '2028-06-30',
        ]);

        [
            ,
            $outgoingPresident,
            ,
            $outgoingPresidentOfficer,
            ,
        ] = $this->createVerifiedRosterWithProjectedAuthority($organization, $activeYear, $osa, 'Outgoing');
        $this->createVerifiedRoster($organization, $incomingYear, $osa, 'Incoming');

        $this->app->bind(OfficerAuthorityProjectionService::class, function (): OfficerAuthorityProjectionService {
            return new class(app(AdminAssignmentService::class)) extends OfficerAuthorityProjectionService
            {
                public function synchronize(OrganizationOfficerRoster $roster, User $approvedBy): void
                {
                    throw new RuntimeException('Injected projection failure.');
                }
            };
        });

        $this->withoutExceptionHandling();

        try {
            $this->actingAs($osa)
                ->postJson(route('dashboard.school-years.transition.close', $activeYear), [
                    'reason' => 'This close should roll back.',
                ]);

            $this->fail('Expected injected projection failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected projection failure.', $exception->getMessage());
        }

        $this->assertNull($activeYear->fresh()->transition_closed_at);
        $this->assertNull($activeYear->fresh()->transition_closed_by);
        $this->assertSame(OrganizationOfficer::STATUS_ACTIVE, $outgoingPresidentOfficer->fresh()->status);
        $this->assertSame(AdminAssignment::STATUS_ACTIVE, $this->assignmentFor($outgoingPresidentOfficer)->fresh()->status);
        $this->assertTrue($outgoingPresident->fresh()->hasRole('Society Admin'));
    }

    private function createOsa(): User
    {
        $user = User::factory()->create([
            'name' => 'OSA Admin',
            'email' => 'osa.transition@usm.edu.ph',
            'user_type' => 'faculty_or_staff',
        ]);

        $user->assignRole('Super Admin (OSA)');

        return $user;
    }

    private function createOrganization(string $acronym): Organization
    {
        $college = College::factory()->create();

        return Organization::create([
            'college_id' => $college->id,
            'name' => "{$acronym} Society",
            'acronym' => $acronym,
            'type' => 'society',
            'status' => 'active',
        ]);
    }

    private function createActiveTransitionYear(User $actor): SchoolYear
    {
        return SchoolYear::factory()->active($actor)->create([
            'label' => '2026-2027',
            'starts_on' => '2026-07-01',
            'ends_on' => '2027-06-30',
            'transition_started_at' => now()->subDay(),
            'transition_ends_at' => now()->addMonth()->toDateString(),
            'transition_opened_by' => $actor->id,
        ]);
    }

    /**
     * @return array{OrganizationOfficerRoster, User, User, OrganizationOfficer, OrganizationOfficer}
     */
    private function createVerifiedRosterWithProjectedAuthority(
        Organization $organization,
        SchoolYear $schoolYear,
        User $actor,
        string $namePrefix
    ): array {
        [$roster, $president, $secretary, $presidentOfficer, $secretaryOfficer] = $this->createVerifiedRoster(
            $organization,
            $schoolYear,
            $actor,
            $namePrefix
        );

        app(OfficerAuthorityProjectionService::class)->synchronize($roster, $actor);

        return [$roster, $president, $secretary, $presidentOfficer, $secretaryOfficer];
    }

    /**
     * @return array{OrganizationOfficerRoster, User, User, OrganizationOfficer, OrganizationOfficer}
     */
    private function createVerifiedRoster(
        Organization $organization,
        SchoolYear $schoolYear,
        User $actor,
        string $namePrefix
    ): array {
        $emailPrefix = strtolower($namePrefix).'.'.strtolower($organization->acronym);
        $president = User::factory()->create([
            'name' => "{$namePrefix} President",
            'email' => "{$emailPrefix}.president@usm.edu.ph",
            'user_type' => 'student',
        ]);
        $secretary = User::factory()->create([
            'name' => "{$namePrefix} Secretary",
            'email' => "{$emailPrefix}.secretary@usm.edu.ph",
            'user_type' => 'student',
        ]);
        $adviser = User::factory()->create([
            'name' => "{$namePrefix} Adviser",
            'email' => "{$emailPrefix}.adviser@usm.edu.ph",
            'user_type' => 'faculty_or_staff',
        ]);

        $roster = OrganizationOfficerRoster::create([
            'organization_id' => $organization->id,
            'school_year_id' => $schoolYear->id,
            'status' => OrganizationOfficerRoster::STATUS_VERIFIED,
            'attestation_text' => "{$namePrefix} roster attestation.",
            'submitted_by' => $president->id,
            'submitted_at' => now()->subHour(),
            'verified_by' => $actor->id,
            'verified_at' => now(),
            'verification_remarks' => "{$namePrefix} roster verified.",
            'created_by' => $president->id,
        ]);

        $presidentOfficer = OrganizationOfficer::create([
            'officer_roster_id' => $roster->id,
            'user_id' => $president->id,
            'position_key' => OrganizationOfficer::POSITION_PRESIDENT,
            'position_title' => 'President',
            'status' => OrganizationOfficer::STATUS_ACTIVE,
            'effective_start' => $schoolYear->starts_on,
        ]);
        $secretaryOfficer = OrganizationOfficer::create([
            'officer_roster_id' => $roster->id,
            'user_id' => $secretary->id,
            'position_key' => OrganizationOfficer::POSITION_SECRETARY,
            'position_title' => 'Secretary',
            'status' => OrganizationOfficer::STATUS_ACTIVE,
            'effective_start' => $schoolYear->starts_on,
        ]);
        OrganizationOfficer::create([
            'officer_roster_id' => $roster->id,
            'user_id' => $adviser->id,
            'position_key' => OrganizationOfficer::POSITION_ADVISER,
            'position_title' => 'Adviser',
            'status' => OrganizationOfficer::STATUS_ACTIVE,
            'effective_start' => $schoolYear->starts_on,
        ]);

        return [$roster, $president, $secretary, $presidentOfficer, $secretaryOfficer];
    }

    private function assignmentFor(OrganizationOfficer $officer): AdminAssignment
    {
        return AdminAssignment::query()
            ->where('source_type', AdminAssignment::SOURCE_VERIFIED_OFFICER)
            ->where('source_id', $officer->id)
            ->firstOrFail();
    }

    private function hasProjectedAssignment(OrganizationOfficer $officer): bool
    {
        return AdminAssignment::query()
            ->where('source_type', AdminAssignment::SOURCE_VERIFIED_OFFICER)
            ->where('source_id', $officer->id)
            ->exists();
    }
}
