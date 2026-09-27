<?php

namespace Tests\Feature;

use App\Models\College;
use App\Models\Department;
use App\Models\Program;
use App\Models\University;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UsmInstitutionalDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DepartmentMasterDataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    /**
     * The public seam for Department master data is the OSA-protected dashboard API.
     */
    public function test_osa_can_list_create_and_update_departments_under_colleges(): void
    {
        [$college] = $this->createCollege();
        $osa = $this->createOsa();

        $this->actingAs($osa)
            ->getJson(route('dashboard.departments.index'))
            ->assertOk()
            ->assertJsonPath('departments', []);

        $createResponse = $this->actingAs($osa)
            ->postJson(route('dashboard.departments.store'), [
                'college_id' => $college->id,
                'name' => 'Department of Information and Communications Technology',
                'code' => 'DICT',
                'status' => 'active',
            ])
            ->assertCreated()
            ->assertJsonPath('department.name', 'Department of Information and Communications Technology')
            ->assertJsonPath('department.code', 'DICT')
            ->assertJsonPath('department.college.id', $college->id);

        $department = Department::findOrFail($createResponse->json('department.id'));

        $this->actingAs($osa)
            ->putJson(route('dashboard.departments.update', $department), [
                'college_id' => $college->id,
                'name' => 'Department of Information and Communication Technology',
                'code' => 'ICT',
                'status' => 'inactive',
            ])
            ->assertOk()
            ->assertJsonPath('department.name', 'Department of Information and Communication Technology')
            ->assertJsonPath('department.code', 'ICT')
            ->assertJsonPath('department.status', 'inactive');

        $department->refresh();

        $this->assertSame($college->id, $department->college_id);
        $this->assertSame('ICT', $department->code);
        $this->assertSame('inactive', $department->status);
        $this->assertTrue($department->college->is($college));
    }

    public function test_department_codes_are_unique_inside_a_college_only(): void
    {
        [$ceit] = $this->createCollege(code: 'CEIT');
        [$csm] = $this->createCollege(code: 'CSM');
        $osa = $this->createOsa();

        Department::create([
            'college_id' => $ceit->id,
            'name' => 'Department of Information and Communication Technology',
            'code' => 'ICT',
            'status' => 'active',
        ]);

        $this->actingAs($osa)
            ->postJson(route('dashboard.departments.store'), [
                'college_id' => $ceit->id,
                'name' => 'Duplicate Department',
                'code' => 'ICT',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');

        $this->actingAs($osa)
            ->postJson(route('dashboard.departments.store'), [
                'college_id' => $csm->id,
                'name' => 'Independent ICT Department',
                'code' => 'ICT',
            ])
            ->assertCreated()
            ->assertJsonPath('department.college.id', $csm->id);

        $this->assertSame(2, Department::where('code', 'ICT')->count());
    }

    public function test_non_osa_users_cannot_manage_departments(): void
    {
        [$college] = $this->createCollege();
        $student = $this->createStudent();

        $this->actingAs($student)
            ->getJson(route('dashboard.departments.index'))
            ->assertForbidden();

        $this->actingAs($student)
            ->postJson(route('dashboard.departments.store'), [
                'college_id' => $college->id,
                'name' => 'Blocked Department',
                'code' => 'BLOCKED',
            ])
            ->assertForbidden();
    }

    public function test_department_foreign_key_cascades_when_college_is_removed(): void
    {
        [$college] = $this->createCollege();
        $department = Department::create([
            'college_id' => $college->id,
            'name' => 'Department of Civil Engineering',
            'code' => 'DCE',
            'status' => 'active',
        ]);

        $college->delete();

        $this->assertModelMissing($department);
    }

    public function test_department_factory_creates_required_parent_records(): void
    {
        $department = Department::factory()->create();

        $this->assertModelExists($department);
        $this->assertNotNull($department->college);
        $this->assertNotNull($department->college->university);
    }

    public function test_usm_seed_data_creates_explicit_departments_with_program_mappings(): void
    {
        $this->seed(UsmInstitutionalDataSeeder::class);

        $ceit = College::where('code', 'CEIT')->firstOrFail();

        $this->assertDatabaseHas('departments', [
            'college_id' => $ceit->id,
            'code' => 'ICT',
            'name' => 'Department of Information and Communication Technology',
        ]);

        $program = Program::where('code', 'BSIS')->firstOrFail();

        $this->assertFalse(Schema::hasColumn('programs', 'college_id'));
        $this->assertSame($ceit->id, $program->department?->college_id);
        $this->assertSame('ICT', $program->department?->code);
        $this->assertTrue($program->college->is($ceit));
        $this->assertTrue($program->resolvedCollege()?->is($ceit));
    }

    private function createCollege(string $code = 'CEIT'): array
    {
        $university = University::create([
            'name' => 'University of Southern Mindanao',
            'domain' => strtolower($code).'.usm.edu.ph',
        ]);

        $college = College::create([
            'university_id' => $university->id,
            'name' => "{$code} College",
            'code' => $code,
        ]);

        return [$college, $university];
    }

    private function createOsa(): User
    {
        $user = User::create([
            'name' => 'OSA Admin',
            'email' => 'osa.departments@usm.edu.ph',
            'password' => null,
        ]);

        $user->assignRole('Super Admin (OSA)');

        return $user;
    }

    private function createStudent(): User
    {
        $user = User::create([
            'name' => 'Student User',
            'email' => 'student.departments@usm.edu.ph',
            'password' => null,
        ]);

        $user->assignRole('Student');

        return $user;
    }
}
