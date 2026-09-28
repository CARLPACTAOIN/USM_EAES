<?php

namespace Tests\Feature;

use App\Models\College;
use App\Models\Department;
use App\Models\Organization;
use App\Models\Program;
use App\Models\University;
use App\Models\User;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UsmInstitutionalDataSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ContractLegacyHierarchyAndAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_programs_table_does_not_have_college_id_column(): void
    {
        $this->assertFalse(
            Schema::hasColumn('programs', 'college_id'),
            'programs table should not have college_id column after domain contraction.'
        );
    }

    public function test_legacy_admin_applications_tables_do_not_exist(): void
    {
        $this->assertFalse(
            Schema::hasTable('admin_applications'),
            'admin_applications table should be dropped after domain contraction.'
        );

        $this->assertFalse(
            Schema::hasTable('admin_application_programs'),
            'admin_application_programs pivot table should be dropped after domain contraction.'
        );
    }

    public function test_organization_memberships_does_not_have_obsolete_academic_year_column(): void
    {
        $this->assertFalse(
            Schema::hasColumn('organization_memberships', 'academic_year'),
            'organization_memberships should not have free-form academic_year column; school_year_id is canonical.'
        );

        $this->assertTrue(
            Schema::hasColumn('organization_memberships', 'school_year_id'),
            'organization_memberships must retain school_year_id foreign key.'
        );
    }

    public function test_program_resolves_college_through_department_relationship(): void
    {
        $university = University::create([
            'name' => 'University of Southern Mindanao',
            'domain' => 'usm.edu.ph',
        ]);

        $college = College::create([
            'university_id' => $university->id,
            'name' => 'College of Engineering and Information Technology',
            'code' => 'CEIT',
        ]);

        $department = Department::create([
            'college_id' => $college->id,
            'name' => 'Department of Information and Communication Technology',
            'code' => 'ICT',
            'status' => 'active',
        ]);

        $program = Program::create([
            'department_id' => $department->id,
            'name' => 'Bachelor of Science in Information Systems',
            'code' => 'BSIS',
        ]);

        $this->assertSame($department->id, $program->department_id);
        $this->assertTrue($program->department->is($department));
        $this->assertTrue($program->college->is($college));
        $this->assertTrue($program->resolvedCollege()->is($college));
    }

    public function test_program_requires_department_owner(): void
    {
        $this->expectException(QueryException::class);

        Program::create([
            'name' => 'Bachelor of Science in Orphaned Data',
            'code' => 'BSOD',
        ]);
    }

    public function test_program_code_is_unique_per_department_not_globally(): void
    {
        [$firstDepartment, $secondDepartment] = $this->createTwoDepartments();

        $firstProgram = Program::create([
            'department_id' => $firstDepartment->id,
            'name' => 'Bachelor of Science in Shared Code',
            'code' => 'BSSC',
        ]);

        $secondProgram = Program::create([
            'department_id' => $secondDepartment->id,
            'name' => 'Bachelor of Science in Shared Code',
            'code' => 'BSSC',
        ]);

        $this->assertNotSame($firstProgram->id, $secondProgram->id);
        $this->assertSame($firstDepartment->id, $firstProgram->department_id);
        $this->assertSame($secondDepartment->id, $secondProgram->department_id);
    }

    public function test_program_code_cannot_repeat_inside_same_department(): void
    {
        [$department] = $this->createTwoDepartments();

        Program::create([
            'department_id' => $department->id,
            'name' => 'Bachelor of Science in First Program',
            'code' => 'BSFP',
        ]);

        $this->expectException(QueryException::class);

        Program::create([
            'department_id' => $department->id,
            'name' => 'Bachelor of Science in Duplicate Program',
            'code' => 'BSFP',
        ]);
    }

    public function test_department_cannot_be_deleted_while_it_owns_programs(): void
    {
        [$department] = $this->createTwoDepartments();

        Program::create([
            'department_id' => $department->id,
            'name' => 'Bachelor of Science in Protected Program',
            'code' => 'BSPP',
        ]);

        $this->expectException(QueryException::class);

        $department->delete();
    }

    public function test_user_and_organization_models_do_not_have_admin_applications_relation(): void
    {
        $user = new User;
        $this->assertFalse(
            method_exists($user, 'adminApplications'),
            'User model should not retain deprecated adminApplications relationship.'
        );

        $organization = new Organization;
        $this->assertFalse(
            method_exists($organization, 'adminApplications'),
            'Organization model should not retain deprecated adminApplications relationship.'
        );
    }

    public function test_institutional_data_seeder_populates_programs_under_departments_without_college_id(): void
    {
        $this->seed(UsmInstitutionalDataSeeder::class);

        $ceit = College::where('code', 'CEIT')->firstOrFail();
        $bsis = Program::where('code', 'BSIS')->firstOrFail();

        $this->assertNotNull($bsis->department_id);
        $this->assertSame('ICT', $bsis->department->code);
        $this->assertTrue($bsis->college->is($ceit));
        $this->assertTrue($bsis->resolvedCollege()->is($ceit));
        $this->assertSame(0, Program::whereNull('department_id')->count());
        $this->assertTrue(
            Program::query()
                ->select('department_id', 'code')
                ->groupBy('department_id', 'code')
                ->havingRaw('COUNT(*) > 1')
                ->doesntExist()
        );
    }

    public function test_demo_data_seeder_populates_only_department_owned_programs(): void
    {
        $this->seed(DemoDataSeeder::class);

        $this->assertSame(0, Program::whereNull('department_id')->count());
        $this->assertTrue(
            Program::query()
                ->select('department_id', 'code')
                ->groupBy('department_id', 'code')
                ->havingRaw('COUNT(*) > 1')
                ->doesntExist()
        );
    }

    /**
     * @return array{0: Department, 1: Department}
     */
    private function createTwoDepartments(): array
    {
        $university = University::create([
            'name' => 'University of Southern Mindanao',
            'domain' => 'usm.edu.ph',
        ]);

        $college = College::create([
            'university_id' => $university->id,
            'name' => 'College of Engineering and Information Technology',
            'code' => 'CEIT',
        ]);

        return [
            Department::create([
                'college_id' => $college->id,
                'name' => 'Department of Information and Communication Technology',
                'code' => 'ICT',
                'status' => 'active',
            ]),
            Department::create([
                'college_id' => $college->id,
                'name' => 'Department of Civil Engineering',
                'code' => 'DCE',
                'status' => 'active',
            ]),
        ];
    }
}
