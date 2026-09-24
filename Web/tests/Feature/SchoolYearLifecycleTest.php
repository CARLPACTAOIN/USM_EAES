<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\SchoolYear;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchoolYearLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_osa_can_create_list_and_activate_school_year_without_calendar_inference(): void
    {
        $osa = $this->createOsa();

        $createResponse = $this->actingAs($osa)
            ->postJson(route('dashboard.school-years.store'), [
                'label' => '2024-2025',
                'starts_on' => '2024-07-01',
                'ends_on' => '2025-06-30',
            ])
            ->assertCreated()
            ->assertJsonPath('school_year.label', '2024-2025')
            ->assertJsonPath('school_year.status', 'draft')
            ->assertJsonPath('school_year.activated_at', null);

        $schoolYear = SchoolYear::findOrFail($createResponse->json('school_year.id'));

        $this->actingAs($osa)
            ->getJson(route('dashboard.school-years.index'))
            ->assertOk()
            ->assertJsonPath('school_years.0.id', $schoolYear->id)
            ->assertJsonPath('school_years.0.status', 'draft');

        $this->actingAs($osa)
            ->postJson(route('dashboard.school-years.activate', $schoolYear))
            ->assertOk()
            ->assertJsonPath('school_year.status', 'active')
            ->assertJsonPath('school_year.activated_by.id', $osa->id);

        $schoolYear->refresh();

        $this->assertSame(SchoolYear::STATUS_ACTIVE, $schoolYear->status);
        $this->assertSame($osa->id, $schoolYear->activated_by);
        $this->assertNotNull($schoolYear->activated_at);
        $this->assertNull($schoolYear->closed_at);
    }

    public function test_activating_school_year_closes_previous_active_year_in_one_lifecycle_action(): void
    {
        $osa = $this->createOsa();
        $previous = SchoolYear::factory()->active($osa)->create([
            'label' => '2025-2026',
            'starts_on' => '2025-07-01',
            'ends_on' => '2026-06-30',
        ]);
        $next = SchoolYear::factory()->create([
            'label' => '2026-2027',
            'starts_on' => '2026-07-01',
            'ends_on' => '2027-06-30',
        ]);

        $this->actingAs($osa)
            ->postJson(route('dashboard.school-years.activate', $next))
            ->assertOk()
            ->assertJsonPath('school_year.id', $next->id)
            ->assertJsonPath('school_year.status', 'active');

        $previous->refresh();
        $next->refresh();

        $this->assertSame(SchoolYear::STATUS_CLOSED, $previous->status);
        $this->assertSame($osa->id, $previous->closed_by);
        $this->assertNotNull($previous->closed_at);
        $this->assertSame(SchoolYear::STATUS_ACTIVE, $next->status);
        $this->assertSame(1, SchoolYear::where('status', SchoolYear::STATUS_ACTIVE)->count());
    }

    public function test_database_prevents_two_active_school_years_if_service_is_bypassed(): void
    {
        $osa = $this->createOsa();

        SchoolYear::factory()->active($osa)->create(['label' => '2026-2027']);

        $this->expectException(QueryException::class);

        SchoolYear::factory()->active($osa)->create(['label' => '2027-2028']);
    }

    public function test_osa_controls_transition_window_on_active_school_year(): void
    {
        $osa = $this->createOsa();
        $schoolYear = SchoolYear::factory()->active($osa)->create([
            'label' => '2026-2027',
        ]);

        $this->actingAs($osa)
            ->postJson(route('dashboard.school-years.transition.open', $schoolYear), [
                'transition_ends_at' => '2026-08-31',
            ])
            ->assertOk()
            ->assertJsonPath('school_year.status', 'active')
            ->assertJsonPath('school_year.transition_opened_by.id', $osa->id)
            ->assertJsonPath('school_year.transition_ends_at', '2026-08-31');

        $schoolYear->refresh();

        $this->assertSame(SchoolYear::STATUS_ACTIVE, $schoolYear->status);
        $this->assertSame($osa->id, $schoolYear->transition_opened_by);
        $this->assertNotNull($schoolYear->transition_started_at);
        $this->assertSame('2026-08-31', $schoolYear->transition_ends_at->toDateString());

        $this->actingAs($osa)
            ->postJson(route('dashboard.school-years.transition.close', $schoolYear))
            ->assertOk()
            ->assertJsonPath('school_year.transition_closed_by.id', $osa->id);

        $schoolYear->refresh();

        $this->assertSame($osa->id, $schoolYear->transition_closed_by);
        $this->assertNotNull($schoolYear->transition_closed_at);
        $this->assertDatabaseHas('audit_logs', [
            'target_id' => $schoolYear->id,
            'admin_id' => $osa->id,
            'action' => 'open-school-year-transition',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'target_id' => $schoolYear->id,
            'admin_id' => $osa->id,
            'action' => 'close-school-year-transition',
        ]);
    }

    public function test_non_osa_users_cannot_manage_school_years(): void
    {
        $student = $this->createStudent();

        $this->actingAs($student)
            ->getJson(route('dashboard.school-years.index'))
            ->assertForbidden();

        $this->actingAs($student)
            ->postJson(route('dashboard.school-years.store'), [
                'label' => '2026-2027',
                'starts_on' => '2026-07-01',
                'ends_on' => '2027-06-30',
            ])
            ->assertForbidden();

        $this->assertSame(0, SchoolYear::count());
        $this->assertSame(0, AuditLog::count());
    }

    private function createOsa(): User
    {
        $user = User::create([
            'name' => 'OSA Admin',
            'email' => 'osa.schoolyear@usm.edu.ph',
            'password' => null,
        ]);

        $user->assignRole('Super Admin (OSA)');

        return $user;
    }

    private function createStudent(): User
    {
        $user = User::create([
            'name' => 'Student User',
            'email' => 'student.schoolyear@usm.edu.ph',
            'password' => null,
        ]);

        $user->assignRole('Student');

        return $user;
    }
}
