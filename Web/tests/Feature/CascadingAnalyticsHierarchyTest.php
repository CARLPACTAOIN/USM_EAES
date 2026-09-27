<?php

namespace Tests\Feature;

use App\Http\Controllers\Web\AnalyticsOverviewController;
use App\Livewire\Dashboard\AnalyticsOverviewFilters;
use App\Models\AttendanceRecord;
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
use App\Services\OrganizationAnalyticsService;
use App\Services\ParticipationScopeService;
use App\Support\EventEvaluationForm;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

class CascadingAnalyticsHierarchyTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_cascading_filter_query_narrowing_options(): void
    {
        $setup = $this->createComplexHierarchy();
        $superAdmin = $this->createSuperAdmin();

        $controller = app(AnalyticsOverviewController::class);

        // 1. No filters: All colleges, departments, programs, and orgs available
        $allData = $controller->dataFor($superAdmin, []);
        $this->assertCount(2, $allData['filterColleges']);
        $this->assertCount(3, $allData['filterDepartments']);
        $this->assertCount(3, $allData['filterPrograms']);
        $this->assertCount(3, $allData['filterOrganizations']);

        // 2. Filter by College 1: Only College 1's departments, programs, and organizations
        $college1Data = $controller->dataFor($superAdmin, [
            'college_id' => $setup['college1']->id,
        ]);
        $this->assertCount(1, $college1Data['filterColleges']);
        $this->assertCount(2, $college1Data['filterDepartments']);
        $this->assertTrue($college1Data['filterDepartments']->contains('id', $setup['dept1A']->id));
        $this->assertTrue($college1Data['filterDepartments']->contains('id', $setup['dept1B']->id));
        $this->assertFalse($college1Data['filterDepartments']->contains('id', $setup['dept2A']->id));
        $this->assertCount(2, $college1Data['filterPrograms']);
        $this->assertTrue($college1Data['filterPrograms']->contains('id', $setup['prog1A']->id));
        $this->assertTrue($college1Data['filterPrograms']->contains('id', $setup['prog1B']->id));
        $this->assertFalse($college1Data['filterPrograms']->contains('id', $setup['prog2A']->id));
        $this->assertCount(2, $college1Data['filterOrganizations']);
        $this->assertTrue($college1Data['filterOrganizations']->contains('id', $setup['org1A']->id));
        $this->assertTrue($college1Data['filterOrganizations']->contains('id', $setup['org1B']->id));
        $this->assertFalse($college1Data['filterOrganizations']->contains('id', $setup['org2A']->id));

        // 3. Filter by Department 1A: Only Dept 1A's programs and organizations
        $dept1AData = $controller->dataFor($superAdmin, [
            'college_id' => $setup['college1']->id,
            'department_id' => $setup['dept1A']->id,
        ]);
        $this->assertCount(1, $dept1AData['filterDepartments']);
        $this->assertCount(1, $dept1AData['filterPrograms']);
        $this->assertSame($setup['prog1A']->id, $dept1AData['filterPrograms']->first()->id);
        $this->assertCount(1, $dept1AData['filterOrganizations']);
        $this->assertSame($setup['org1A']->id, $dept1AData['filterOrganizations']->first()->id);

        // 4. Filter by Program 1A: Only Org 1A
        $prog1AData = $controller->dataFor($superAdmin, [
            'program_id' => $setup['prog1A']->id,
        ]);
        $this->assertCount(1, $prog1AData['filterPrograms']);
        $this->assertCount(1, $prog1AData['filterOrganizations']);
        $this->assertSame($setup['org1A']->id, $prog1AData['filterOrganizations']->first()->id);
    }

    public function test_livewire_cascading_filter_component_reactivity(): void
    {
        $setup = $this->createComplexHierarchy();
        $superAdmin = $this->createSuperAdmin();

        $this->actingAs($superAdmin);

        Livewire::test(AnalyticsOverviewFilters::class)
            ->set('collegeId', $setup['college1']->id)
            ->assertSet('collegeId', $setup['college1']->id)
            ->set('departmentId', $setup['dept1A']->id)
            ->assertSet('departmentId', $setup['dept1A']->id)
            ->set('programId', $setup['prog1A']->id)
            ->assertSet('programId', $setup['prog1A']->id)
            ->set('organizationId', $setup['org1A']->id)
            ->assertSet('organizationId', $setup['org1A']->id)
            // Changing college should cascade reset department, program, and organization
            ->set('collegeId', $setup['college2']->id)
            ->assertSet('departmentId', '')
            ->assertSet('programId', '')
            ->assertSet('organizationId', '')
            // Setting new department and program
            ->set('departmentId', $setup['dept2A']->id)
            ->set('programId', $setup['prog2A']->id)
            ->assertSet('departmentId', $setup['dept2A']->id)
            // Changing department should cascade reset program and organization
            ->set('departmentId', '')
            ->assertSet('programId', '')
            ->assertSet('organizationId', '')
            // Reset filters clears all selections
            ->set('collegeId', $setup['college1']->id)
            ->call('clearFilters')
            ->assertSet('collegeId', '')
            ->assertSet('departmentId', '')
            ->assertSet('programId', '')
            ->assertSet('organizationId', '');
    }

    public function test_attendance_rate_and_active_membership_ratio_strictly_scope_to_active_school_year_memberships(): void
    {
        $setup = $this->createComplexHierarchy();
        $superAdmin = $this->createSuperAdmin();
        $sy2026 = $setup['sy2026'];
        $sy2025 = $setup['sy2025'];
        $org = $setup['org1A'];
        $prog = $setup['prog1A'];
        $dept = $setup['dept1A'];
        $col = $setup['college1'];

        // SY 2026-2027 Members:
        // 3 active members
        $studentA = $this->createStudent($org, $prog, $dept, $col, 'Student A', '3rd_year', $sy2026, OrganizationMembership::STATUS_ACTIVE);
        $studentB = $this->createStudent($org, $prog, $dept, $col, 'Student B', '3rd_year', $sy2026, OrganizationMembership::STATUS_ACTIVE);
        $studentC = $this->createStudent($org, $prog, $dept, $col, 'Student C', '3rd_year', $sy2026, OrganizationMembership::STATUS_ACTIVE);
        // 1 inactive member
        $studentD = $this->createStudent($org, $prog, $dept, $col, 'Student D', '3rd_year', $sy2026, 'inactive');

        // SY 2025-2026 Member:
        // 1 active member for previous year only (must NOT be counted in 2026-2027 denominator)
        $studentOld = $this->createStudent($org, $prog, $dept, $col, 'Student Old', '4th_year', $sy2025, OrganizationMembership::STATUS_ACTIVE);

        // Outsider from different org:
        $outsider = $this->createStudent($setup['org2A'], $setup['prog2A'], $setup['dept2A'], $setup['college2'], 'Outsider', '3rd_year', $sy2026, OrganizationMembership::STATUS_ACTIVE);

        // Event in SY 2026-2027
        $event = Event::create([
            'organization_id' => $org->id,
            'school_year_id' => $sy2026->id,
            'department_id' => $dept->id,
            'title' => 'SY 2026-2027 General Assembly',
            'proposal_category' => 'activity',
            'status' => Event::STATUS_COMPLETED,
            'lifecycle_phase' => 'completed',
            'event_domain' => 'student_affairs',
            'start_date' => '2026-08-15',
            'end_date' => '2026-08-15',
            'location_type' => 'on-campus',
        ]);
        EventEvaluationForm::createForEvent($event, null, [[
            'id' => 'quality',
            'label' => 'Quality',
            'description' => 'Event quality',
        ]]);

        $scope = app(ParticipationScopeService::class)->ensureOrganizerScope($event->fresh(['organization']));

        // Student A and B attend (2 out of 3 active members)
        $this->recordAttendance($event, $studentA, $scope);
        $this->recordAttendance($event, $studentB, $scope);
        // Student D (inactive) and outsider attend as well
        $this->recordAttendance($event, $studentD, $scope);
        $this->recordAttendance($event, $outsider, $scope);

        // Query metrics for SY 2026-2027
        $service = app(OrganizationAnalyticsService::class);
        $metrics = $service->organizationMetrics(
            $org,
            null,
            $sy2026->label,
            null,
            null,
            null,
            $dept->id,
            $col->id,
        );

        // Active membership checks:
        // Total members for SY 2026-2027 in this org: 3 (Student A, B, C - excluding inactive Student D and old SY Student Old)
        $this->assertSame(3, $metrics['total_members']);
        // Active members strictly (attended >= 1 event): 2 (Student A and B)
        $this->assertSame(2, $metrics['active_members']);
        // Ratio: 2 / 3 = 66.67%
        $this->assertEquals(66.67, $metrics['active_member_ratio']);

        // Attendance rate:
        // Eligible active audience in SY 2026-2027 = 3 (Student A, B, C)
        // Attended active audience = 2 (Student A, B)
        // Attendance rate: (2 / 3) * 100 = 66.67%
        $this->assertEquals(66.67, $metrics['avg_attendance_rate']);
    }

    public function test_gawad_parangal_api_consumes_hierarchy_filters_and_enforces_tenant_boundaries(): void
    {
        $setup = $this->createComplexHierarchy();
        $lsgAdmin = $this->createAdmin('LSG Admin', null, $setup['college1'], null, $setup['sy2026']);
        $deptAdmin = $this->createAdmin('Academic Unit Admin', null, $setup['college1'], $setup['dept1A'], $setup['sy2026']);
        $societyAdmin = $this->createAdmin('Society Admin', $setup['org1A'], $setup['college1'], $setup['dept1A'], $setup['sy2026']);
        $superAdmin = $this->createSuperAdmin();

        // 1. LSG Admin: Can query Org 1A (in College 1), forbidden for Org 2A (in College 2)
        Sanctum::actingAs($lsgAdmin);
        $this->getJson("/api/v1/organizations/{$setup['org1A']->id}/gawad-metrics?school_year={$setup['sy2026']->label}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('organization.id', $setup['org1A']->id);

        $this->getJson("/api/v1/organizations/{$setup['org2A']->id}/gawad-metrics")
            ->assertForbidden();

        // 2. Academic Unit Admin: Can query Org 1A (in Dept 1A), forbidden for Org 1B (in Dept 1B)
        Sanctum::actingAs($deptAdmin);
        $this->getJson("/api/v1/organizations/{$setup['org1A']->id}/gawad-metrics")
            ->assertOk();

        $this->getJson("/api/v1/organizations/{$setup['org1B']->id}/gawad-metrics")
            ->assertForbidden();

        // 3. Society Admin: Can query Org 1A, forbidden for Org 1B
        Sanctum::actingAs($societyAdmin);
        $this->getJson("/api/v1/organizations/{$setup['org1A']->id}/gawad-metrics")
            ->assertOk();

        $this->getJson("/api/v1/organizations/{$setup['org1B']->id}/gawad-metrics")
            ->assertForbidden();

        // 4. Super Admin: Can query any organization and pass cascading department/school_year filters
        Sanctum::actingAs($superAdmin);
        $this->getJson("/api/v1/organizations/{$setup['org1A']->id}/gawad-metrics?department_id={$setup['dept1A']->id}&college_id={$setup['college1']->id}&school_year={$setup['sy2026']->label}")
            ->assertOk()
            ->assertJsonStructure([
                'success',
                'organization' => ['id', 'name', 'acronym'],
                'metrics' => [
                    'total_members',
                    'active_members',
                    'membership_active_ratio',
                    'average_event_attendance_rate',
                    'average_evaluation_score',
                ],
            ]);
    }

    /**
     * @return array{
     *     university: University,
     *     college1: College,
     *     college2: College,
     *     dept1A: Department,
     *     dept1B: Department,
     *     dept2A: Department,
     *     prog1A: Program,
     *     prog1B: Program,
     *     prog2A: Program,
     *     org1A: Organization,
     *     org1B: Organization,
     *     org2A: Organization,
     *     sy2026: SchoolYear,
     *     sy2025: SchoolYear
     * }
     */
    private function createComplexHierarchy(): array
    {
        $sequence = ++$this->sequence;
        $university = University::firstOrCreate(
            ['domain' => 'usm.edu.ph'],
            ['name' => 'University of Southern Mindanao']
        );

        $sy2025 = SchoolYear::firstOrCreate(['label' => '2025-2026'], [
            'starts_on' => '2025-08-01',
            'ends_on' => '2026-07-31',
            'is_current' => false,
        ]);
        $sy2026 = SchoolYear::firstOrCreate(['label' => '2026-2027'], [
            'starts_on' => '2026-08-01',
            'ends_on' => '2027-07-31',
            'status' => SchoolYear::STATUS_ACTIVE,
            'is_current' => true,
        ]);

        // College 1
        $college1 = College::create([
            'university_id' => $university->id,
            'name' => "College of Science {$sequence}",
            'code' => "CS{$sequence}",
        ]);
        $dept1A = Department::create([
            'college_id' => $college1->id,
            'name' => "Department of Math {$sequence}",
            'code' => "DM{$sequence}",
        ]);
        $dept1B = Department::create([
            'college_id' => $college1->id,
            'name' => "Department of Bio {$sequence}",
            'code' => "DB{$sequence}",
        ]);
        $prog1A = Program::create([
            'college_id' => $college1->id,
            'department_id' => $dept1A->id,
            'name' => "BS Math {$sequence}",
            'code' => "BSM{$sequence}",
        ]);
        $prog1B = Program::create([
            'college_id' => $college1->id,
            'department_id' => $dept1B->id,
            'name' => "BS Bio {$sequence}",
            'code' => "BSB{$sequence}",
        ]);
        $org1A = Organization::create([
            'college_id' => $college1->id,
            'department_id' => $dept1A->id,
            'name' => "Math Society {$sequence}",
            'acronym' => "MS{$sequence}",
            'type' => 'society',
            'status' => 'active',
        ]);
        $org1A->programs()->attach($prog1A->id);

        $org1B = Organization::create([
            'college_id' => $college1->id,
            'department_id' => $dept1B->id,
            'name' => "Bio Society {$sequence}",
            'acronym' => "BSOC{$sequence}",
            'type' => 'society',
            'status' => 'active',
        ]);
        $org1B->programs()->attach($prog1B->id);

        // College 2
        $college2 = College::create([
            'university_id' => $university->id,
            'name' => "College of Engineering {$sequence}",
            'code' => "COE{$sequence}",
        ]);
        $dept2A = Department::create([
            'college_id' => $college2->id,
            'name' => "Department of Civil {$sequence}",
            'code' => "DC{$sequence}",
        ]);
        $prog2A = Program::create([
            'college_id' => $college2->id,
            'department_id' => $dept2A->id,
            'name' => "BS Civil Eng {$sequence}",
            'code' => "BSCE{$sequence}",
        ]);
        $org2A = Organization::create([
            'college_id' => $college2->id,
            'department_id' => $dept2A->id,
            'name' => "Civil Society {$sequence}",
            'acronym' => "CSOC{$sequence}",
            'type' => 'society',
            'status' => 'active',
        ]);
        $org2A->programs()->attach($prog2A->id);

        return compact(
            'university',
            'college1',
            'college2',
            'dept1A',
            'dept1B',
            'dept2A',
            'prog1A',
            'prog1B',
            'prog2A',
            'org1A',
            'org1B',
            'org2A',
            'sy2026',
            'sy2025'
        );
    }

    private function createSuperAdmin(): User
    {
        $sequence = ++$this->sequence;
        $user = User::create([
            'name' => "Super Admin {$sequence}",
            'email' => "super.admin.{$sequence}@usm.edu.ph",
            'password' => bcrypt('password123'),
            'user_type' => 'faculty_or_staff_pending',
        ]);
        $user->assignRole('Super Admin (OSA)');

        return $user;
    }

    private function createAdmin(
        string $role,
        ?Organization $organization = null,
        ?College $college = null,
        ?Department $department = null,
        ?SchoolYear $schoolYear = null
    ): User {
        $sequence = ++$this->sequence;
        $admin = User::create([
            'name' => "{$role} Admin {$sequence}",
            'email' => "admin.{$sequence}@usm.edu.ph",
            'password' => bcrypt('password123'),
            'user_type' => 'faculty_or_staff_pending',
        ]);

        app(AdminAssignmentService::class)->createAssignment(
            user: $admin,
            roleName: $role,
            organization: $organization,
            college: $college ?: $organization?->college,
            academicYear: $schoolYear?->label ?? '2026-2027',
            termStart: '2026-08-01',
            termEnd: '2027-07-31',
            positionTitle: "{$role} Title",
            approvedBy: $admin,
            isPrimaryAdmin: false,
            department: $department ?: $organization?->department,
        );

        return $admin->fresh();
    }

    private function createStudent(
        Organization $organization,
        Program $program,
        Department $department,
        College $college,
        string $name,
        string $yearLevel,
        SchoolYear $schoolYear,
        string $status = OrganizationMembership::STATUS_ACTIVE
    ): User {
        $sequence = ++$this->sequence;
        $student = User::create([
            'name' => $name,
            'email' => "student.{$sequence}@usm.edu.ph",
            'password' => null,
            'organization_id' => $organization->id,
            'college_id' => $college->id,
            'department_id' => $department->id,
            'program_id' => $program->id,
            'student_id_number' => "26-{$sequence}",
            'qr_code_value' => "QR-CASCADE-{$sequence}",
            'program_code' => $program->code,
            'year_level' => $yearLevel,
            'user_type' => 'student',
        ]);
        $student->assignRole('Student');

        OrganizationMembership::create([
            'user_id' => $student->id,
            'organization_id' => $organization->id,
            'college_id' => $college->id,
            'department_id' => $department->id,
            'program_id' => $program->id,
            'school_year_id' => $schoolYear->id,
            'academic_year' => $schoolYear->label,
            'year_level' => $yearLevel,
            'membership_type' => 'primary_academic',
            'status' => $status,
            'source' => 'test',
        ]);

        return $student;
    }

    private function recordAttendance(Event $event, User $student, EventParticipationScope $scope): void
    {
        AttendanceRecord::create([
            'event_id' => $event->id,
            'event_participation_scope_id' => $scope->id,
            'student_id' => $student->id,
            'time_in' => $event->start_date->copy()->setTime(8, 0),
            'time_out' => $event->end_date->copy()->setTime(12, 0),
            'society_status' => 'present_on_time',
            'competition_status' => 'present_on_time',
            'left_early' => false,
            'valid' => true,
        ]);
    }
}
