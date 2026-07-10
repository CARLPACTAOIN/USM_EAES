<?php

namespace Tests\Feature;

use App\Models\AdminAssignment;
use App\Models\College;
use App\Models\Organization;
use App\Models\Program;
use App\Models\University;
use App\Models\User;
use App\Services\AdminAssignmentService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AdminOnboardingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_student_cannot_access_admin_application_routes(): void
    {
        $student = $this->createStudent();

        $this->actingAs($student)
            ->get('/portal/admin-applications')
            ->assertNotFound();

        $this->actingAs($student)
            ->post('/portal/admin-applications')
            ->assertNotFound();
    }

    public function test_student_portal_does_not_show_admin_application_link(): void
    {
        $this->actingAs($this->createStudent())
            ->get(route('portal'))
            ->assertOk()
            ->assertDontSee('Admin Application');
    }

    public function test_osa_can_create_society_organization(): void
    {
        [$college, $program] = $this->createCollegeAndProgram();
        $osa = $this->createOsa();

        $this->actingAs($osa)
            ->post(route('dashboard.organizations.store'), [
                'name' => 'Philippine Society of Information Technology Students',
                'acronym' => 'PSITS',
                'type' => 'society',
                'college_id' => $college->id,
                'program_ids' => [$program->id],
                'status' => 'active',
            ])
            ->assertRedirect(route('dashboard.admin-users') . '?tab=organizations')
            ->assertSessionHasNoErrors();

        $organization = Organization::where('acronym', 'PSITS')->firstOrFail();
        $this->assertSame('society', $organization->type);
        $this->assertTrue($organization->programs()->where('program_id', $program->id)->exists());
        $this->assertDatabaseHas('audit_logs', [
            'target_id' => $organization->id,
            'admin_id' => $osa->id,
            'action' => 'create-organization',
        ]);
    }

    public function test_osa_can_assign_society_admin_without_changing_student_profile(): void
    {
        [$college, $program] = $this->createCollegeAndProgram();
        $homeOrganization = Organization::create([
            'college_id' => $college->id,
            'name' => 'Home Society',
            'acronym' => 'HOME',
            'type' => 'society',
            'status' => 'active',
        ]);
        $adminOrganization = Organization::create([
            'college_id' => $college->id,
            'name' => 'PSITS',
            'acronym' => 'PSITS',
            'type' => 'society',
            'status' => 'active',
        ]);

        $student = $this->createStudent();
        $student->update([
            'organization_id' => $homeOrganization->id,
            'college_id' => $college->id,
            'program_id' => $program->id,
            'student_id_number' => '2026-0001',
            'qr_code_value' => 'QR-HOME-STUDENT',
        ]);

        $this->actingAs($this->createOsa())
            ->post(route('dashboard.admin-users.create'), [
                'student_id_number' => '2026-0001',
                'email' => $student->email,
                'role' => 'Society Admin',
                'organization_id' => $adminOrganization->id,
                'college_id' => $college->id,
                'academic_year' => '2026-2027',
                'term_start' => '2026-07-01',
                'term_end' => '2027-06-30',
                'position_title' => 'President',
            ])
            ->assertRedirect(route('dashboard.admin-users') . '?tab=assignments')
            ->assertSessionHasNoErrors();

        $student->refresh();
        $this->assertTrue($student->hasRole('Society Admin'));
        $this->assertSame($homeOrganization->id, $student->organization_id);
        $this->assertSame($college->id, $student->college_id);
        $this->assertSame($program->id, $student->program_id);

        $this->assertDatabaseHas('admin_assignments', [
            'user_id' => $student->id,
            'role_name' => 'Society Admin',
            'organization_id' => $adminOrganization->id,
            'status' => AdminAssignment::STATUS_ACTIVE,
        ]);
    }

    public function test_osa_can_assign_usg_and_lsg_admins_with_correct_scope(): void
    {
        [$college] = $this->createCollegeAndProgram();
        $usg = Organization::create([
            'college_id' => null,
            'name' => 'University Student Government',
            'acronym' => 'USG',
            'type' => 'usg',
            'status' => 'active',
        ]);
        $lsg = Organization::create([
            'college_id' => $college->id,
            'name' => 'CEIT Local Student Government',
            'acronym' => 'CEIT LSG',
            'type' => 'lsg',
            'status' => 'active',
        ]);

        $usgOfficer = $this->createStudent('usg.officer@usm.edu.ph');
        $lsgOfficer = $this->createStudent('lsg.officer@usm.edu.ph');
        $osa = $this->createOsa();

        $this->actingAs($osa)
            ->post(route('dashboard.admin-users.create'), [
                'email' => $usgOfficer->email,
                'role' => 'USG Admin',
                'organization_id' => $usg->id,
                'academic_year' => '2026-2027',
            ])
            ->assertSessionHasNoErrors();

        $this->actingAs($osa)
            ->post(route('dashboard.admin-users.create'), [
                'email' => $lsgOfficer->email,
                'role' => 'LSG Admin',
                'college_id' => $college->id,
                'organization_id' => $lsg->id,
                'academic_year' => '2026-2027',
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('admin_assignments', [
            'user_id' => $usgOfficer->id,
            'role_name' => 'USG Admin',
            'organization_id' => $usg->id,
            'college_id' => null,
            'status' => AdminAssignment::STATUS_ACTIVE,
        ]);

        $this->assertDatabaseHas('admin_assignments', [
            'user_id' => $lsgOfficer->id,
            'role_name' => 'LSG Admin',
            'organization_id' => $lsg->id,
            'college_id' => $college->id,
            'status' => AdminAssignment::STATUS_ACTIVE,
        ]);
    }

    public function test_osa_cannot_assign_super_admin_through_admin_access(): void
    {
        $osa = $this->createOsa();

        $this->actingAs($osa)
            ->post(route('dashboard.admin-users.create'), [
                'name' => 'New OSA Staff',
                'email' => 'newosa@usm.edu.ph',
                'password' => 'password123',
                'role' => 'Super Admin (OSA)',
                'academic_year' => '2026-2027',
            ])
            ->assertSessionHasErrors('role');

        $this->assertDatabaseMissing('users', [
            'email' => 'newosa@usm.edu.ph',
        ]);

        $this->assertDatabaseMissing('admin_assignments', [
            'role_name' => 'Super Admin (OSA)',
            'academic_year' => '2026-2027',
        ]);
    }

    public function test_osa_cannot_assign_hr_admin_through_admin_access(): void
    {
        $osa = $this->createOsa();
        $hrUnit = Organization::create([
            'college_id' => null,
            'name' => 'Human Resource Management Office',
            'acronym' => 'HRMO',
            'type' => 'hr_unit',
            'status' => 'active',
        ]);

        $this->actingAs($osa)
            ->post(route('dashboard.admin-users.create'), [
                'name' => 'HR Staff',
                'email' => 'hr.staff@usm.edu.ph',
                'password' => 'password123',
                'role' => 'HR Admin',
                'organization_id' => $hrUnit->id,
                'academic_year' => '2026-2027',
            ])
            ->assertSessionHasErrors('role');

        $this->assertDatabaseMissing('users', [
            'email' => 'hr.staff@usm.edu.ph',
        ]);

        $this->assertDatabaseMissing('admin_assignments', [
            'role_name' => 'HR Admin',
            'organization_id' => $hrUnit->id,
            'academic_year' => '2026-2027',
        ]);
    }

    public function test_osa_can_assign_academic_unit_and_office_event_admins(): void
    {
        [$college] = $this->createCollegeAndProgram();
        $academicUnit = Organization::create([
            'college_id' => $college->id,
            'name' => 'CEIT Academic Programs Office',
            'acronym' => 'CEIT APO',
            'type' => 'academic_unit',
            'status' => 'active',
        ]);
        $office = Organization::create([
            'college_id' => null,
            'name' => 'Student Affairs and Services Office',
            'acronym' => 'SASO',
            'type' => 'administrative_office',
            'status' => 'active',
        ]);
        $academicAdmin = $this->createStudent('academic.admin@usm.edu.ph');
        $officeAdmin = $this->createStudent('office.admin@usm.edu.ph');
        $osa = $this->createOsa();

        $this->actingAs($osa)
            ->post(route('dashboard.admin-users.create'), [
                'email' => $academicAdmin->email,
                'role' => 'Academic Unit Admin',
                'organization_id' => $academicUnit->id,
                'college_id' => $college->id,
                'academic_year' => '2026-2027',
            ])
            ->assertSessionHasNoErrors();

        $this->actingAs($osa)
            ->post(route('dashboard.admin-users.create'), [
                'email' => $officeAdmin->email,
                'role' => 'Office Event Admin',
                'organization_id' => $office->id,
                'academic_year' => '2026-2027',
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('admin_assignments', [
            'user_id' => $academicAdmin->id,
            'role_name' => 'Academic Unit Admin',
            'organization_id' => $academicUnit->id,
            'college_id' => $college->id,
            'status' => AdminAssignment::STATUS_ACTIVE,
        ]);

        $this->assertDatabaseHas('admin_assignments', [
            'user_id' => $officeAdmin->id,
            'role_name' => 'Office Event Admin',
            'organization_id' => $office->id,
            'college_id' => null,
            'status' => AdminAssignment::STATUS_ACTIVE,
        ]);
    }

    public function test_non_osa_cannot_manage_organizations_or_assign_admins(): void
    {
        [$college, $program] = $this->createCollegeAndProgram();
        $organization = Organization::create([
            'college_id' => $college->id,
            'name' => 'PSITS',
            'acronym' => 'PSITS',
            'type' => 'society',
            'status' => 'active',
        ]);
        $societyAdmin = $this->createStudent('society.admin@usm.edu.ph');
        $societyAdmin->assignRole('Society Admin');

        $this->actingAs($societyAdmin)
            ->get(route('dashboard.admin-users'))
            ->assertForbidden();

        $this->actingAs($societyAdmin)
            ->post(route('dashboard.organizations.store'), [
                'name' => 'Blocked Society',
                'acronym' => 'BLOCK',
                'type' => 'society',
                'college_id' => $college->id,
                'program_ids' => [$program->id],
            ])
            ->assertForbidden();

        $this->actingAs($societyAdmin)
            ->post(route('dashboard.admin-users.create'), [
                'email' => 'another@usm.edu.ph',
                'role' => 'Society Admin',
                'organization_id' => $organization->id,
            ])
            ->assertForbidden();
    }

    public function test_osa_admin_access_page_renders_assignment_and_organization_controls(): void
    {
        [$college] = $this->createCollegeAndProgram();
        Organization::create(['college_id' => $college->id, 'name' => 'PSITS', 'acronym' => 'PSITS', 'type' => 'society', 'status' => 'active']);
        Organization::create(['college_id' => $college->id, 'name' => 'CEIT Local Student Government', 'acronym' => 'CEIT LSG', 'type' => 'lsg', 'status' => 'active']);
        Organization::create(['college_id' => null, 'name' => 'University Student Government', 'acronym' => 'USG', 'type' => 'usg', 'status' => 'active']);

        $response = $this->actingAs($this->createOsa())
            ->get(route('dashboard.admin-users'))
            ->assertOk()
            ->assertSee('Assign Admin Authority')
            ->assertSee('Organizations')
            ->assertSee('Admin Assignments')
            ->assertDontSee('Pending Applications');

        $content = $response->getContent();
        $modalStart = strpos($content, 'id="assign-admin-modal"');
        $modalEnd = strpos($content, '</dialog>', $modalStart);
        $this->assertNotFalse($modalStart);
        $this->assertNotFalse($modalEnd);
        $assignmentModal = substr($content, $modalStart, $modalEnd - $modalStart);

        $this->assertStringContainsString('USG Admin', $assignmentModal);
        $this->assertStringContainsString('LSG Admin', $assignmentModal);
        $this->assertStringContainsString('Society Admin', $assignmentModal);
        $this->assertStringContainsString('Academic Unit Admin', $assignmentModal);
        $this->assertStringContainsString('Office Event Admin', $assignmentModal);
        $this->assertStringNotContainsString('Super Admin (OSA)', $assignmentModal);
        $this->assertStringNotContainsString('HR Admin', $assignmentModal);
    }

    public function test_assignment_service_still_projects_bootstrap_super_admin_and_hr_admin_roles(): void
    {
        $osa = $this->createOsa();
        $hrUnit = Organization::create([
            'college_id' => null,
            'name' => 'Human Resource Management Office',
            'acronym' => 'HRMO',
            'type' => 'hr_unit',
            'status' => 'active',
        ]);
        $bootstrapOsa = User::create([
            'name' => 'Bootstrap OSA',
            'email' => 'bootstrap.osa@usm.edu.ph',
            'password' => null,
        ]);
        $hrAdmin = User::create([
            'name' => 'Bootstrap HR',
            'email' => 'bootstrap.hr@usm.edu.ph',
            'password' => null,
        ]);
        $service = app(AdminAssignmentService::class);

        $service->createAssignment(
            user: $bootstrapOsa,
            roleName: 'Super Admin (OSA)',
            organization: null,
            college: null,
            academicYear: '2026-2027',
            termStart: null,
            termEnd: null,
            positionTitle: 'OSA Bootstrap',
            approvedBy: $osa,
            isPrimaryAdmin: false
        );

        $service->createAssignment(
            user: $hrAdmin,
            roleName: 'HR Admin',
            organization: $hrUnit,
            college: null,
            academicYear: '2026-2027',
            termStart: null,
            termEnd: null,
            positionTitle: 'HR Bootstrap',
            approvedBy: $osa,
            isPrimaryAdmin: false
        );

        $this->assertTrue($bootstrapOsa->fresh()->hasRole('Super Admin (OSA)'));
        $this->assertTrue($hrAdmin->fresh()->hasRole('HR Admin'));
    }

    public function test_assignment_service_blocks_duplicate_active_primary_admins_but_allows_non_primary(): void
    {
        [$college] = $this->createCollegeAndProgram();
        $organization = Organization::create([
            'college_id' => $college->id,
            'name' => 'PSITS',
            'acronym' => 'PSITS',
            'type' => 'society',
            'status' => 'active',
        ]);

        $osa = $this->createOsa();
        $first = $this->createStudent('first@usm.edu.ph');
        $second = $this->createStudent('second@usm.edu.ph');
        $service = app(AdminAssignmentService::class);

        $service->createAssignment(
            user: $first,
            roleName: 'Society Admin',
            organization: $organization,
            college: $college,
            academicYear: '2026-2027',
            termStart: '2026-07-01',
            termEnd: '2027-06-30',
            positionTitle: 'President',
            approvedBy: $osa,
            isPrimaryAdmin: true
        );

        $this->expectException(ValidationException::class);

        try {
            $service->createAssignment(
                user: $second,
                roleName: 'Society Admin',
                organization: $organization,
                college: $college,
                academicYear: '2026-2027',
                termStart: '2026-07-01',
                termEnd: '2027-06-30',
                positionTitle: 'President',
                approvedBy: $osa,
                isPrimaryAdmin: true
            );
        } finally {
            $service->createAssignment(
                user: $second,
                roleName: 'Society Admin',
                organization: $organization,
                college: $college,
                academicYear: '2026-2027',
                termStart: '2026-07-01',
                termEnd: '2027-06-30',
                positionTitle: 'Vice President',
                approvedBy: $osa,
                isPrimaryAdmin: false
            );

            $this->assertDatabaseHas('admin_assignments', [
                'user_id' => $second->id,
                'role_name' => 'Society Admin',
                'is_primary_admin' => false,
                'status' => AdminAssignment::STATUS_ACTIVE,
            ]);
        }
    }

    public function test_admin_assignment_sync_preserves_student_profile_membership(): void
    {
        [$college, $program] = $this->createCollegeAndProgram();
        $studentOrg = Organization::create([
            'college_id' => $college->id,
            'name' => 'Student Home Society',
            'acronym' => 'SHS',
            'type' => 'society',
            'status' => 'active',
        ]);
        $adminOrg = Organization::create([
            'college_id' => null,
            'name' => 'University Student Government',
            'acronym' => 'USG',
            'type' => 'usg',
            'status' => 'active',
        ]);
        $student = $this->createStudent('profiled.student@usm.edu.ph');
        $student->update([
            'organization_id' => $studentOrg->id,
            'college_id' => $college->id,
            'program_id' => $program->id,
            'student_id_number' => '2026-0002',
            'qr_code_value' => 'QR-PROFILED-STUDENT',
        ]);

        $osa = $this->createOsa();
        $service = app(AdminAssignmentService::class);

        $assignment = $service->createAssignment(
            user: $student,
            roleName: 'USG Admin',
            organization: $adminOrg,
            college: null,
            academicYear: '2026-2027',
            termStart: '2026-07-01',
            termEnd: '2027-06-30',
            positionTitle: 'USG officer',
            approvedBy: $osa,
            isPrimaryAdmin: true
        );

        $student->refresh();
        $this->assertTrue($student->hasRole('USG Admin'));
        $this->assertSame($studentOrg->id, $student->organization_id);
        $this->assertSame($college->id, $student->college_id);
        $this->assertSame($program->id, $student->program_id);

        $service->revokeAssignment($assignment, $osa, 'Rotation ended.');

        $student->refresh();
        $this->assertFalse($student->hasRole('USG Admin'));
        $this->assertSame($studentOrg->id, $student->organization_id);
        $this->assertSame($college->id, $student->college_id);
        $this->assertSame($program->id, $student->program_id);
    }

    private function createCollegeAndProgram(): array
    {
        $university = University::create(['name' => 'USM', 'domain' => 'usm.edu.ph']);
        $college = College::create(['university_id' => $university->id, 'name' => 'CEIT', 'code' => 'CEIT']);
        $program = Program::create(['college_id' => $college->id, 'name' => 'BS Information Systems', 'code' => 'BSIS']);

        return [$college, $program];
    }

    private function createStudent(string $email = 'student@usm.edu.ph'): User
    {
        $user = User::create([
            'name' => 'Student User',
            'email' => $email,
            'password' => null,
        ]);
        $user->assignRole('Student');

        return $user;
    }

    private function createOsa(): User
    {
        $user = User::create([
            'name' => 'OSA Admin',
            'email' => 'osa@usm.edu.ph',
            'password' => null,
        ]);
        $user->assignRole('Super Admin (OSA)');

        return $user;
    }
}
