<?php

namespace Tests\Feature;

use App\Models\College;
use App\Models\Department;
use App\Models\Organization;
use App\Models\Program;
use App\Models\University;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UsmInstitutionalDataSeeder;
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
    }
}
