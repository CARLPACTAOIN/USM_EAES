<?php

namespace Tests\Feature;

use App\Models\AdminApplication;
use App\Models\AdminAssignment;
use App\Models\College;
use App\Models\Organization;
use App\Models\Program;
use App\Models\University;
use App\Models\User;
use App\Services\AdminAssignmentService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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

    public function test_student_can_apply_for_new_society_and_osa_approval_creates_assignment(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        [$college, $program] = $this->createCollegeAndProgram();
        $student = $this->createStudent();
        $osa = $this->createOsa();

        $this->actingAs($student)
            ->post(route('portal.admin-applications.store'), [
                'request_type' => 'new_society',
                'college_id' => $college->id,
                'organization_name' => 'Philippine Society of Information Technology Students',
                'organization_acronym' => 'PSITS',
                'program_ids' => [$program->id],
                'adviser_name' => 'Prof. Adviser',
                'academic_year' => '2026-2027',
                'term_start' => '2026-07-01',
                'term_end' => '2027-06-30',
                'position_title' => 'President',
                'proof_document' => UploadedFile::fake()->create('appointment.pdf', 120, 'application/pdf'),
                'logo' => UploadedFile::fake()->image('logo.png'),
            ])
            ->assertRedirect(route('portal.admin-applications'))
            ->assertSessionHasNoErrors();

        $application = AdminApplication::firstOrFail();
        $this->assertSame(AdminApplication::STATUS_PENDING, $application->status);

        $this->actingAs($osa)
            ->post(route('dashboard.admin-applications.approve', $application))
            ->assertRedirect(route('dashboard.admin-users'))
            ->assertSessionHasNoErrors();

        $organization = Organization::where('acronym', 'PSITS')->firstOrFail();

        $this->assertDatabaseHas('admin_assignments', [
            'user_id' => $student->id,
            'role_name' => 'Society Admin',
            'organization_id' => $organization->id,
            'college_id' => $college->id,
            'academic_year' => '2026-2027',
            'status' => AdminAssignment::STATUS_ACTIVE,
            'is_primary_admin' => true,
        ]);

        $this->assertTrue($organization->programs()->where('program_id', $program->id)->exists());
        $this->assertTrue($student->fresh()->hasRole('Society Admin'));
        $this->assertSame($organization->id, $student->fresh()->organization_id);
        $this->assertDatabaseHas('audit_logs', [
            'target_id' => $application->id,
            'admin_id' => $osa->id,
            'action' => 'approve-admin-application',
        ]);
    }

    public function test_duplicate_pending_application_for_same_scope_is_rejected(): void
    {
        Storage::fake('local');

        [$college, $program] = $this->createCollegeAndProgram();
        $student = $this->createStudent();

        $payload = [
            'request_type' => 'new_society',
            'college_id' => $college->id,
            'organization_name' => 'Test Society',
            'organization_acronym' => 'TS',
            'program_ids' => [$program->id],
            'academic_year' => '2026-2027',
            'proof_document' => UploadedFile::fake()->create('proof.pdf', 100, 'application/pdf'),
        ];

        $this->actingAs($student)
            ->post(route('portal.admin-applications.store'), $payload)
            ->assertSessionHasNoErrors();

        $payload['proof_document'] = UploadedFile::fake()->create('proof-2.pdf', 100, 'application/pdf');

        $this->actingAs($student)
            ->post(route('portal.admin-applications.store'), $payload)
            ->assertSessionHasErrors('request_type');

        $this->assertSame(1, AdminApplication::count());
    }

    public function test_osa_admin_access_page_renders_assignment_filter_controls(): void
    {
        [$college] = $this->createCollegeAndProgram();
        Organization::create(['college_id' => $college->id, 'name' => 'PSITS', 'acronym' => 'PSITS', 'type' => 'society']);
        Organization::create(['college_id' => $college->id, 'name' => 'CEIT Local Student Government', 'acronym' => 'CEIT LSG', 'type' => 'lsg']);
        Organization::create(['college_id' => null, 'name' => 'University Student Government', 'acronym' => 'USG', 'type' => 'usg']);
        Organization::create(['college_id' => null, 'name' => 'Admission and Records Office', 'acronym' => 'ARO', 'type' => 'aro']);

        $this->actingAs($this->createOsa())
            ->get(route('dashboard.admin-users'))
            ->assertOk()
            ->assertSee('Assign Admin')
            ->assertSee('roleTypes', false)
            ->assertSee('filteredOrganizations', false);
    }

    public function test_assignment_service_blocks_duplicate_active_primary_admins_but_allows_non_primary(): void
    {
        [$college] = $this->createCollegeAndProgram();
        $organization = Organization::create([
            'college_id' => $college->id,
            'name' => 'PSITS',
            'acronym' => 'PSITS',
            'type' => 'society',
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
