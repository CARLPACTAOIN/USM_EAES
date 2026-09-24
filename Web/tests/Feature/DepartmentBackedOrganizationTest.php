<?php

namespace Tests\Feature;

use App\Models\College;
use App\Models\Department;
use App\Models\Organization;
use App\Models\Program;
use App\Models\University;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DepartmentBackedOrganizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_osa_can_create_department_backed_society_for_programs_in_same_department(): void
    {
        [$college, $ict, , $bsis, $bscs] = $this->createHierarchy();
        $osa = $this->createOsa();

        $this->actingAs($osa)
            ->post(route('dashboard.organizations.store'), [
                'name' => 'Philippine Society of Information Technology Students',
                'acronym' => 'PSITS',
                'type' => 'society',
                'college_id' => $college->id,
                'program_ids' => [$bsis->id, $bscs->id],
                'status' => 'active',
            ])
            ->assertRedirect(route('dashboard.admin-users').'?tab=organizations')
            ->assertSessionHasNoErrors();

        $organization = Organization::where('acronym', 'PSITS')->firstOrFail();

        $this->assertSame($college->id, $organization->college_id);
        $this->assertSame($ict->id, $organization->department_id);
        $this->assertTrue($organization->programs()->whereKey($bsis->id)->exists());
        $this->assertTrue($organization->programs()->whereKey($bscs->id)->exists());
        $this->assertTrue($organization->resolvedCollege()?->is($college));
        $this->assertTrue($bsis->resolvedCollege()?->is($college));
    }

    public function test_department_backed_society_rejects_programs_from_another_department(): void
    {
        [$college, $ict, , $bsis, , $civilEngineering] = $this->createHierarchy();
        $osa = $this->createOsa();

        $this->actingAs($osa)
            ->post(route('dashboard.organizations.store'), [
                'name' => 'Cross Department Society',
                'acronym' => 'CDS',
                'type' => 'society',
                'college_id' => $college->id,
                'department_id' => $ict->id,
                'program_ids' => [$bsis->id, $civilEngineering->id],
                'status' => 'active',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors(['program_ids'], null, 'organization');

        $this->assertDatabaseMissing('organizations', [
            'acronym' => 'CDS',
        ]);
    }

    public function test_department_backed_academic_unit_rejects_programs_from_another_department(): void
    {
        [$college, $ict, , $bsis, , $civilEngineering] = $this->createHierarchy();
        $osa = $this->createOsa();

        $this->actingAs($osa)
            ->post(route('dashboard.organizations.store'), [
                'name' => 'CEIT Academic Unit',
                'acronym' => 'CEIT-AU',
                'type' => 'academic_unit',
                'college_id' => $college->id,
                'department_id' => $ict->id,
                'program_ids' => [$bsis->id, $civilEngineering->id],
                'status' => 'active',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors(['program_ids'], null, 'organization');

        $this->assertDatabaseMissing('organizations', [
            'acronym' => 'CEIT-AU',
        ]);
    }

    public function test_direct_pivot_write_rejects_cross_department_coverage_when_organization_has_department_context(): void
    {
        [$college, $ict, , , , $civilEngineering] = $this->createHierarchy();

        $organization = Organization::create([
            'college_id' => $college->id,
            'department_id' => $ict->id,
            'name' => 'Philippine Society of Information Technology Students',
            'acronym' => 'PSITS',
            'type' => 'society',
            'status' => 'active',
        ]);

        try {
            $organization->programs()->attach($civilEngineering->id);
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('program_ids', $exception->errors());
            $this->assertFalse($organization->programs()->whereKey($civilEngineering->id)->exists());

            return;
        }

        $this->fail('Cross-department program coverage was not rejected.');
    }

    /**
     * @return array{0: College, 1: Department, 2: Department, 3: Program, 4: Program, 5: Program}
     */
    private function createHierarchy(): array
    {
        $university = University::factory()->create([
            'name' => 'University of Southern Mindanao',
            'domain' => 'usm.edu.ph',
        ]);

        $college = College::factory()->for($university)->create([
            'university_id' => $university->id,
            'name' => 'College of Engineering and Information Technology',
            'code' => 'CEIT',
        ]);

        $ict = Department::factory()->for($college)->create([
            'college_id' => $college->id,
            'name' => 'Department of Information and Communication Technology',
            'code' => 'ICT',
            'status' => 'active',
        ]);

        $civil = Department::factory()->for($college)->create([
            'college_id' => $college->id,
            'name' => 'Department of Civil Engineering',
            'code' => 'DCE',
            'status' => 'active',
        ]);

        $bsis = Program::create([
            'college_id' => $college->id,
            'department_id' => $ict->id,
            'name' => 'BS Information Systems',
            'code' => 'BSIS',
        ]);

        $bscs = Program::create([
            'college_id' => $college->id,
            'department_id' => $ict->id,
            'name' => 'BS Computer Science',
            'code' => 'BSCS',
        ]);

        $civilEngineering = Program::create([
            'college_id' => $college->id,
            'department_id' => $civil->id,
            'name' => 'BS Civil Engineering',
            'code' => 'BSCE',
        ]);

        return [$college, $ict, $civil, $bsis, $bscs, $civilEngineering];
    }

    private function createOsa(): User
    {
        $user = User::factory()->create([
            'name' => 'OSA Admin',
            'email' => 'osa.department-backed@usm.edu.ph',
            'password' => null,
        ]);

        $user->assignRole('Super Admin (OSA)');

        return $user;
    }
}
