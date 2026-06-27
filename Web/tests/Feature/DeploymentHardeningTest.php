<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\College;
use App\Models\Organization;
use App\Models\University;
use App\Models\User;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeploymentHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_security_headers_are_present_when_enabled(): void
    {
        config(['services.eaes.security_headers' => true]);

        $this->get('/login')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Permissions-Policy', 'camera=(self), microphone=(), geolocation=()')
            ->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_hsts_is_present_only_under_https_or_forced_https(): void
    {
        config([
            'services.eaes.security_headers' => true,
            'services.eaes.force_https' => false,
        ]);

        $this->get('/login')->assertHeaderMissing('Strict-Transport-Security');

        $this->withHeader('X-Forwarded-Proto', 'https')
            ->get('/login')
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');

        config(['services.eaes.force_https' => true]);

        $this->get('/login')
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    public function test_dev_login_requires_local_env_and_explicit_opt_in(): void
    {
        config([
            'app.env' => 'local',
            'services.eaes.dev_login_enabled' => false,
        ]);

        $this->get('/dev/login/Society%20Admin')->assertNotFound();

        config(['services.eaes.dev_login_enabled' => true]);

        $this->get('/dev/login/Society%20Admin')
            ->assertRedirect(route('dashboard'));
    }

    public function test_deployment_check_passes_for_demo_profile(): void
    {
        $this->applyDemoSafeConfig();

        $this->artisan('eaes:deployment-check', ['--profile' => 'demo'])
            ->assertExitCode(0);
    }

    public function test_deployment_check_fails_for_unsafe_production_profile(): void
    {
        config([
            'app.debug' => true,
            'app.env' => 'production',
            'app.url' => 'http://eaes.test',
            'session.secure' => false,
            'session.encrypt' => false,
            'queue.default' => 'database',
            'cache.default' => 'database',
            'sanctum.stateful' => ['localhost', '127.0.0.1:8000'],
            'services.eaes.dev_login_enabled' => true,
            'services.gemini.api_key' => null,
        ]);

        $this->artisan('eaes:deployment-check', ['--profile' => 'production'])
            ->assertExitCode(1);
    }

    public function test_admin_surfaces_are_blocked_for_guest_and_student(): void
    {
        $university = University::create(['name' => 'USM', 'domain' => 'usm.edu.ph']);
        $college = College::create(['university_id' => $university->id, 'name' => 'CEIT', 'code' => 'CEIT']);
        $org = Organization::create([
            'college_id' => $college->id,
            'name' => 'PSITS',
            'acronym' => 'PSITS',
            'type' => 'society',
        ]);
        $student = User::create([
            'name' => 'Blocked Student',
            'email' => 'blocked.student@usm.edu.ph',
            'organization_id' => $org->id,
        ]);
        $student->assignRole('Student');

        $event = Event::create([
            'organization_id' => $org->id,
            'title' => 'Protected Export Event',
            'status' => 'completed',
            'start_date' => today(),
            'end_date' => today(),
        ]);

        $this->get(route('dashboard.ai'))->assertRedirect('/login');
        $this->get(route('dashboard.pending-links'))->assertRedirect('/login');
        $this->get(route('dashboard.events.exports.attendance.excel', $event))->assertRedirect('/login');

        $this->actingAs($student)->get(route('dashboard.ai'))->assertForbidden();
        $this->actingAs($student)->post(route('dashboard.ai.query'), ['query' => 'show events'])->assertForbidden();
        $this->actingAs($student)->get(route('dashboard.pending-links'))->assertForbidden();
        $this->actingAs($student)->get(route('dashboard.admin-users'))->assertForbidden();
        $this->actingAs($student)->get(route('dashboard.events.exports.attendance.excel', $event))->assertForbidden();
    }

    public function test_demo_seeder_is_optional_and_creates_expected_accounts(): void
    {
        $this->artisan('db:seed')->assertExitCode(0);

        $this->assertDatabaseMissing('users', ['email' => 'osa.demo@usm.edu.ph']);
        $this->assertDatabaseMissing('users', ['email' => 'society.demo@usm.edu.ph']);
        $this->assertDatabaseMissing('users', ['email' => 'student.demo@usm.edu.ph']);

        $this->seed(DemoDataSeeder::class);

        $this->assertDatabaseHas('users', ['email' => 'osa.demo@usm.edu.ph']);
        $this->assertDatabaseHas('users', ['email' => 'society.demo@usm.edu.ph']);
        $this->assertDatabaseHas('users', ['email' => 'student.demo@usm.edu.ph']);

        $this->assertTrue(User::where('email', 'osa.demo@usm.edu.ph')->first()->hasRole('Super Admin (OSA)'));
        $this->assertTrue(User::where('email', 'society.demo@usm.edu.ph')->first()->hasRole('Society Admin'));
        $this->assertTrue(User::where('email', 'student.demo@usm.edu.ph')->first()->hasRole('Student'));
    }

    private function applyDemoSafeConfig(): void
    {
        config([
            'app.debug' => false,
            'app.env' => 'local',
            'app.url' => 'https://eaes-demo.local',
            'session.secure' => true,
            'session.encrypt' => true,
            'queue.default' => 'database',
            'cache.default' => 'database',
            'sanctum.stateful' => ['eaes-demo.local'],
            'services.eaes.dev_login_enabled' => false,
            'services.gemini.api_key' => 'demo-key',
        ]);
    }
}
