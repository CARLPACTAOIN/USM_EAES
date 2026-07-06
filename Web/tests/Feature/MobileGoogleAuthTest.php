<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MobileGoogleAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        config([
            'services.eaes.allowed_email_domain' => 'usm.edu.ph',
            'services.google.mobile_client_ids' => ['web-client-id'],
        ]);
    }

    public function test_mobile_google_login_accepts_verified_institutional_token(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/tokeninfo*' => Http::response([
                'aud' => 'web-client-id',
                'email' => 's.scanner@usm.edu.ph',
                'email_verified' => 'true',
                'name' => 'Scanner Student',
                'sub' => 'google-sub-1',
            ], 200),
        ]);

        $this->postJson('/api/v1/auth/google/mobile', [
            'id_token' => 'valid-id-token',
            'device_name' => 'android-phone',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('user.email', 's.scanner@usm.edu.ph')
            ->assertJsonPath('abilities.0', 'scanner-assignments');

        $this->assertDatabaseHas(User::class, [
            'email' => 's.scanner@usm.edu.ph',
            'google_sub' => 'google-sub-1',
            'user_type' => 'student',
        ]);
    }

    public function test_mobile_google_login_rejects_non_institutional_email(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/tokeninfo*' => Http::response([
                'aud' => 'web-client-id',
                'email' => 'personal@gmail.com',
                'email_verified' => 'true',
                'name' => 'Personal User',
                'sub' => 'google-sub-personal',
            ], 200),
        ]);

        $this->postJson('/api/v1/auth/google/mobile', [
            'id_token' => 'personal-id-token',
            'device_name' => 'android-phone',
        ])
            ->assertStatus(403)
            ->assertJsonPath(
                'message',
                'Access restricted to institutional @usm.edu.ph accounts.'
            );

        $this->assertDatabaseMissing(User::class, ['email' => 'personal@gmail.com']);
    }

    public function test_mobile_google_login_rejects_unconfigured_token_audience(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/tokeninfo*' => Http::response([
                'aud' => 'unexpected-client-id',
                'email' => 's.scanner@usm.edu.ph',
                'email_verified' => 'true',
                'name' => 'Scanner Student',
                'sub' => 'google-sub-1',
            ], 200),
        ]);

        $this->postJson('/api/v1/auth/google/mobile', [
            'id_token' => 'wrong-audience-token',
            'device_name' => 'android-phone',
        ])
            ->assertStatus(401)
            ->assertJsonPath('message', 'Google authentication failed.');

        $this->assertDatabaseMissing(User::class, ['email' => 's.scanner@usm.edu.ph']);
    }
}
