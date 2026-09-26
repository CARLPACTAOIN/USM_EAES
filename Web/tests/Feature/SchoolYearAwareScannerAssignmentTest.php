<?php

namespace Tests\Feature;

use App\Models\College;
use App\Models\Department;
use App\Models\Event;
use App\Models\EventParticipationScope;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Program;
use App\Models\SchoolYear;
use App\Models\University;
use App\Models\User;
use App\Services\AdminAssignmentService;
use App\Services\ParticipationScopeService;
use App\Services\ScannerAssignmentService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SchoolYearAwareScannerAssignmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_scanner_assignment_binds_to_school_year_and_participation_scope(): void
    {
        $hierarchy = $this->createHierarchy();
        $schoolYear = $hierarchy['schoolYear'];
        $org = $hierarchy['org'];
        $admin = $this->createAdmin('Society Admin', $org, $schoolYear);
        $student = $this->createStudent($org, $schoolYear);

        $event = Event::create([
            'organization_id' => $org->id,
            'school_year_id' => $schoolYear->id,
            'title' => 'SY-Bound Event',
            'status' => 'approved',
            'start_date' => today(),
            'end_date' => today(),
        ]);

        $participationScopes = app(ParticipationScopeService::class);
        $scope = $participationScopes->ensureOrganizerScope($event->fresh(['organization']));

        $this->assertSame($schoolYear->id, $scope->school_year_id);

        $scannerAssignments = app(ScannerAssignmentService::class);
        $assignment = $scannerAssignments->assign(
            event: $event,
            scannerUser: $student,
            assignedBy: $admin,
            label: 'Door 1',
            participationScope: $scope
        );

        $this->assertSame($schoolYear->id, $assignment->school_year_id);
        $this->assertSame($scope->id, $assignment->event_participation_scope_id);
        $this->assertSame('active', $assignment->status);
        $this->assertSame($student->id, $assignment->scanner_user_id);
        $this->assertSame($admin->id, $assignment->assigned_by_user_id);

        $this->assertDatabaseHas('event_scanner_assignments', [
            'id' => $assignment->id,
            'event_id' => $event->id,
            'school_year_id' => $schoolYear->id,
            'event_participation_scope_id' => $scope->id,
            'scanner_user_id' => $student->id,
            'status' => 'active',
        ]);
    }

    public function test_scanner_assignment_denied_if_manager_authority_projection_is_for_different_school_year(): void
    {
        $hierarchy = $this->createHierarchy();
        $schoolYearActive = $hierarchy['schoolYear'];
        $org = $hierarchy['org'];

        $osa = $this->createOsa();
        $schoolYearPrevious = SchoolYear::factory()->create([
            'label' => '2024-2025',
            'starts_on' => '2024-07-01',
            'ends_on' => '2025-06-30',
            'status' => SchoolYear::STATUS_CLOSED,
            'closed_by' => $osa->id,
            'closed_at' => now(),
        ]);

        // Manager holds projection for previous school year
        $previousYearManager = $this->createAdmin('Society Admin', $org, $schoolYearPrevious);
        $student = $this->createStudent($org, $schoolYearActive);

        $event = Event::create([
            'organization_id' => $org->id,
            'school_year_id' => $schoolYearActive->id,
            'title' => 'Active SY Event',
            'status' => 'approved',
            'start_date' => today(),
            'end_date' => today(),
        ]);

        $scope = app(ParticipationScopeService::class)->ensureOrganizerScope($event->fresh(['organization']));

        $this->expectException(ValidationException::class);
        app(ScannerAssignmentService::class)->assign(
            event: $event,
            scannerUser: $student,
            assignedBy: $previousYearManager,
            label: 'Entrance',
            participationScope: $scope
        );
    }

    public function test_denial_of_cross_tenant_scanner_management(): void
    {
        $hierarchy = $this->createHierarchy();
        $schoolYear = $hierarchy['schoolYear'];
        $orgA = $hierarchy['org'];
        $orgB = Organization::create([
            'college_id' => $hierarchy['college']->id,
            'name' => 'Society Beta',
            'acronym' => 'BETA',
            'type' => 'society',
        ]);
        $orgB->programs()->attach($hierarchy['program']->id);

        $adminA = $this->createAdmin('Society Admin', $orgA, $schoolYear);
        $studentB = $this->createStudent($orgB, $schoolYear);

        $event = Event::create([
            'organization_id' => $orgA->id,
            'school_year_id' => $schoolYear->id,
            'title' => 'Joint Summit',
            'status' => 'approved',
            'start_date' => today(),
            'end_date' => today(),
        ]);

        $participationScopes = app(ParticipationScopeService::class);
        $organizerScope = $participationScopes->ensureOrganizerScope($event->fresh(['organization']));

        // Add Org B as participant scope
        $scopeB = EventParticipationScope::create([
            'event_id' => $event->id,
            'parent_scope_id' => $organizerScope->id,
            'school_year_id' => $schoolYear->id,
            'participation_role' => EventParticipationScope::ROLE_PARTICIPANT,
            'participant_type' => 'society',
            'organization_id' => $orgB->id,
            'college_id' => $hierarchy['college']->id,
            'participant_name_snapshot' => 'Society Beta',
            'ppa_required' => false,
            'ppa_status' => EventParticipationScope::PPA_APPROVED,
            'capture_mode' => 'self_scanning',
            'analytics_rollup_enabled' => true,
            'status' => EventParticipationScope::STATUS_ACTIVE,
        ]);

        // Manager A cannot manage Org B's scope
        $this->assertFalse($participationScopes->canManageScope($adminA, $scopeB));

        $this->expectException(ValidationException::class);
        app(ScannerAssignmentService::class)->assign(
            event: $event,
            scannerUser: $studentB,
            assignedBy: $adminA,
            label: 'Beta Gate',
            participationScope: $scopeB
        );
    }

    public function test_delegated_scanning_allows_delegated_organization_manager_in_matching_school_year(): void
    {
        $hierarchy = $this->createHierarchy();
        $schoolYear = $hierarchy['schoolYear'];
        $hostOrg = $hierarchy['org'];

        $partnerOrg = Organization::create([
            'college_id' => $hierarchy['college']->id,
            'name' => 'Delegated Scanning Society',
            'acronym' => 'DSS',
            'type' => 'society',
        ]);
        $partnerOrg->programs()->attach($hierarchy['program']->id);

        $partnerAdmin = $this->createAdmin('Society Admin', $partnerOrg, $schoolYear);
        $partnerStudent = $this->createStudent($partnerOrg, $schoolYear);

        $event = Event::create([
            'organization_id' => $hostOrg->id,
            'school_year_id' => $schoolYear->id,
            'title' => 'Delegated Scanning Event',
            'status' => 'approved',
            'start_date' => today(),
            'end_date' => today(),
        ]);

        $participationScopes = app(ParticipationScopeService::class);
        $organizerScope = $participationScopes->ensureOrganizerScope($event->fresh(['organization']));

        $delegatedScope = EventParticipationScope::create([
            'event_id' => $event->id,
            'parent_scope_id' => $organizerScope->id,
            'school_year_id' => $schoolYear->id,
            'participation_role' => EventParticipationScope::ROLE_PARTICIPANT,
            'participant_type' => 'society',
            'organization_id' => $hostOrg->id,
            'delegated_to_organization_id' => $partnerOrg->id,
            'participant_name_snapshot' => 'Delegated Scope Partner',
            'ppa_required' => false,
            'ppa_status' => EventParticipationScope::PPA_APPROVED,
            'capture_mode' => 'delegated_scanning',
            'analytics_rollup_enabled' => true,
            'status' => EventParticipationScope::STATUS_ACTIVE,
        ]);

        // Partner admin in same school year CAN manage delegated scope
        $this->assertTrue($participationScopes->canManageScope($partnerAdmin, $delegatedScope));

        $assignment = app(ScannerAssignmentService::class)->assign(
            event: $event,
            scannerUser: $partnerStudent,
            assignedBy: $partnerAdmin,
            label: 'Partner Scanner Gate',
            participationScope: $delegatedScope
        );

        $this->assertSame($delegatedScope->id, $assignment->event_participation_scope_id);
        $this->assertSame($schoolYear->id, $assignment->school_year_id);
        $this->assertSame($partnerStudent->id, $assignment->scanner_user_id);
    }

    public function test_department_scope_and_academic_unit_admin_scanner_assignment(): void
    {
        $hierarchy = $this->createHierarchy();
        $schoolYear = $hierarchy['schoolYear'];
        $college = $hierarchy['college'];
        $dept = $hierarchy['dept'];

        $academicAdmin = $this->createAcademicAdmin($college, $dept, $schoolYear);
        $deptStudent = $this->createStudentWithDepartment($college, $dept, $hierarchy['program'], $schoolYear);

        $otherDept = Department::create([
            'college_id' => $college->id,
            'name' => 'Department of Agriculture',
            'code' => 'DA',
            'status' => 'active',
        ]);
        $otherStudent = $this->createStudentWithDepartment($college, $otherDept, $hierarchy['program'], $schoolYear);

        $event = Event::create([
            'organization_id' => $hierarchy['org']->id,
            'department_id' => $dept->id,
            'school_year_id' => $schoolYear->id,
            'title' => 'Department Colloquium',
            'status' => 'approved',
            'start_date' => today(),
            'end_date' => today(),
        ]);

        $participationScopes = app(ParticipationScopeService::class);
        $organizerScope = $participationScopes->ensureOrganizerScope($event->fresh(['organization']));

        $deptScope = EventParticipationScope::create([
            'event_id' => $event->id,
            'parent_scope_id' => $organizerScope->id,
            'school_year_id' => $schoolYear->id,
            'department_id' => $dept->id,
            'college_id' => $college->id,
            'participation_role' => EventParticipationScope::ROLE_PARTICIPANT,
            'participant_type' => 'department',
            'participant_name_snapshot' => 'DIT Department Audience',
            'ppa_required' => false,
            'ppa_status' => EventParticipationScope::PPA_APPROVED,
            'capture_mode' => 'self_scanning',
            'analytics_rollup_enabled' => true,
            'status' => EventParticipationScope::STATUS_ACTIVE,
        ]);

        $this->assertTrue($participationScopes->canManageScope($academicAdmin, $deptScope));

        // Academic unit admin can assign student from same department
        $assignment = app(ScannerAssignmentService::class)->assign(
            event: $event,
            scannerUser: $deptStudent,
            assignedBy: $academicAdmin,
            label: 'DIT Scanner',
            participationScope: $deptScope
        );

        $this->assertSame($deptScope->id, $assignment->event_participation_scope_id);
        $this->assertSame($schoolYear->id, $assignment->school_year_id);

        // Academic unit admin cannot assign student from different department
        $this->expectException(ValidationException::class);
        app(ScannerAssignmentService::class)->assign(
            event: $event,
            scannerUser: $otherStudent,
            assignedBy: $academicAdmin,
            label: 'Other Dept Scanner',
            participationScope: $deptScope
        );
    }

    public function test_scanner_candidates_listing_filters_by_scope_and_school_year(): void
    {
        $hierarchy = $this->createHierarchy();
        $schoolYear = $hierarchy['schoolYear'];
        $org = $hierarchy['org'];
        $admin = $this->createAdmin('Society Admin', $org, $schoolYear);
        $studentInOrg = $this->createStudent($org, $schoolYear);

        $otherOrg = Organization::create([
            'college_id' => $hierarchy['college']->id,
            'name' => 'Outside Society',
            'acronym' => 'OUTSIDE',
            'type' => 'society',
        ]);
        $otherOrg->programs()->attach($hierarchy['program']->id);
        $studentOutside = $this->createStudent($otherOrg, $schoolYear);

        $event = Event::create([
            'organization_id' => $org->id,
            'school_year_id' => $schoolYear->id,
            'title' => 'Candidate Listing Test Event',
            'status' => 'approved',
            'start_date' => today(),
            'end_date' => today(),
        ]);

        $scope = app(ParticipationScopeService::class)->ensureOrganizerScope($event->fresh(['organization']));

        // Web dashboard candidate endpoint
        $response = $this->actingAs($admin)
            ->getJson(route('dashboard.events.scanner-candidates', [
                'event' => $event->id,
                'event_participation_scope_id' => $scope->id,
            ]))
            ->assertOk();

        $candidates = collect($response->json('data'));
        $this->assertTrue($candidates->contains('id', $studentInOrg->id));
        $this->assertFalse($candidates->contains('id', $studentOutside->id));
    }

    public function test_web_route_scanner_assignment_enforces_school_year_and_scope(): void
    {
        $hierarchy = $this->createHierarchy();
        $schoolYear = $hierarchy['schoolYear'];
        $org = $hierarchy['org'];
        $admin = $this->createAdmin('Society Admin', $org, $schoolYear);
        $student = $this->createStudent($org, $schoolYear);

        $event = Event::create([
            'organization_id' => $org->id,
            'school_year_id' => $schoolYear->id,
            'title' => 'Web Route Assignment Event',
            'status' => 'approved',
            'start_date' => today(),
            'end_date' => today(),
        ]);

        $scope = app(ParticipationScopeService::class)->ensureOrganizerScope($event->fresh(['organization']));

        $this->actingAs($admin)
            ->post(route('dashboard.events.scanner-assignments.store', $event->id), [
                'event_participation_scope_id' => $scope->id,
                'scanner_user_id' => $student->id,
                'label' => 'South Gate',
            ])
            ->assertSessionHas('success');

        $this->assertDatabaseHas('event_scanner_assignments', [
            'event_id' => $event->id,
            'school_year_id' => $schoolYear->id,
            'event_participation_scope_id' => $scope->id,
            'scanner_user_id' => $student->id,
            'label' => 'South Gate',
            'status' => 'active',
        ]);
    }

    /**
     * @return array{
     *     university: University,
     *     college: College,
     *     dept: Department,
     *     program: Program,
     *     org: Organization,
     *     schoolYear: SchoolYear,
     *     osa: User
     * }
     */
    private function createHierarchy(): array
    {
        $university = University::create(['name' => 'University of Southern Mindanao', 'domain' => 'usm.edu.ph']);
        $college = College::create(['university_id' => $university->id, 'name' => 'College of Engineering and Information Technology', 'code' => 'CEIT']);
        $dept = Department::create(['college_id' => $college->id, 'name' => 'Department of Information Technology', 'code' => 'DIT']);
        $program = Program::create(['college_id' => $college->id, 'department_id' => $dept->id, 'name' => 'BS Information Systems', 'code' => 'BSIS']);
        $org = Organization::create([
            'college_id' => $college->id,
            'department_id' => $dept->id,
            'name' => 'Philippine Society of Information Technology Students',
            'acronym' => 'PSITS',
            'type' => 'society',
        ]);
        $org->programs()->attach($program->id);

        $osa = $this->createOsa();
        $schoolYear = SchoolYear::factory()->active($osa)->create([
            'label' => '2026-2027',
            'starts_on' => '2026-07-01',
            'ends_on' => '2027-06-30',
        ]);

        return compact('university', 'college', 'dept', 'program', 'org', 'schoolYear', 'osa');
    }

    private function createOsa(): User
    {
        $user = User::factory()->create([
            'name' => 'OSA Officer',
            'email' => 'osa-'.uniqid().'@usm.edu.ph',
        ]);
        $user->assignRole('Super Admin (OSA)');

        return $user;
    }

    private function createAdmin(string $roleName, Organization $organization, SchoolYear $schoolYear): User
    {
        $user = User::factory()->create([
            'name' => "Admin {$roleName}",
            'email' => 'admin-'.uniqid().'@usm.edu.ph',
            'organization_id' => $organization->id,
            'college_id' => $organization->college_id,
        ]);
        $user->givePermissionTo('assign-scanners-own');

        app(AdminAssignmentService::class)->createAssignment(
            user: $user,
            roleName: $roleName,
            organization: $organization,
            college: $organization->college,
            academicYear: $schoolYear->label,
            termStart: $schoolYear->starts_on?->toDateString(),
            termEnd: $schoolYear->ends_on?->toDateString(),
            positionTitle: 'Student Leader',
            approvedBy: $user,
            isPrimaryAdmin: true,
        );

        return $user;
    }

    private function createAcademicAdmin(College $college, Department $department, SchoolYear $schoolYear): User
    {
        $user = User::factory()->create([
            'name' => 'Academic Unit Admin',
            'email' => 'academic-admin-'.uniqid().'@usm.edu.ph',
            'college_id' => $college->id,
            'department_id' => $department->id,
        ]);
        $user->assignRole('Academic Unit Admin');
        $user->givePermissionTo('assign-scanners-own');

        app(AdminAssignmentService::class)->createAssignment(
            user: $user,
            roleName: 'Academic Unit Admin',
            organization: null,
            college: $college,
            academicYear: $schoolYear->label,
            termStart: $schoolYear->starts_on?->toDateString(),
            termEnd: $schoolYear->ends_on?->toDateString(),
            positionTitle: 'Department Head',
            approvedBy: $user,
            isPrimaryAdmin: true,
            department: $department,
        );

        return $user;
    }

    private function createStudent(Organization $organization, SchoolYear $schoolYear): User
    {
        $studentId = '2024-'.fake()->unique()->numberBetween(10000, 99999);
        $user = User::factory()->create([
            'name' => 'Student '.fake()->lastName(),
            'email' => 'student-'.uniqid().'@usm.edu.ph',
            'organization_id' => $organization->id,
            'college_id' => $organization->college_id,
            'program_id' => $organization->programs()->first()?->id,
            'student_id_number' => $studentId,
            'qr_code_value' => 'QR-'.$studentId,
        ]);
        $user->assignRole('Student');

        OrganizationMembership::create([
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'school_year_id' => $schoolYear->id,
            'academic_year' => $schoolYear->label,
            'status' => 'active',
        ]);

        return $user;
    }

    private function createStudentWithDepartment(College $college, Department $department, Program $program, SchoolYear $schoolYear): User
    {
        $studentId = '2024-'.fake()->unique()->numberBetween(10000, 99999);

        $user = User::factory()->create([
            'name' => 'Dept Student '.fake()->lastName(),
            'email' => 'deptstudent-'.uniqid().'@usm.edu.ph',
            'college_id' => $college->id,
            'department_id' => $department->id,
            'program_id' => $program->id,
            'student_id_number' => $studentId,
            'qr_code_value' => 'QR-'.$studentId,
        ]);
        $user->assignRole('Student');

        return $user;
    }
}
