<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApiRateLimitingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_password_login_returns_stable_json_and_rate_limit_headers_when_throttled(): void
    {
        $user = $this->createApiUser('rate-login@usm.edu.ph', ['Society Admin']);
        config(['services.eaes.rate_limits.login_per_minute' => 5]);

        $payload = [
            'email' => $user->email,
            'password' => 'wrong-password',
            'device_name' => 'rate-limit-test-device',
        ];

        foreach (range(1, 5) as $attempt) {
            $this->postJson('/api/v1/auth/login', $payload)->assertUnauthorized();
        }

        $response = $this->postJson('/api/v1/auth/login', $payload)
            ->assertTooManyRequests()
            ->assertJson([
                'success' => false,
                'message' => 'Too many requests. Please retry later.',
            ]);

        $this->assertIsInt($response->json('retry_after'));
        $this->assertSame('5', $response->headers->get('X-RateLimit-Limit'));
        $this->assertSame('0', $response->headers->get('X-RateLimit-Remaining'));
        $this->assertNotNull($response->headers->get('Retry-After'));
    }

    public function test_google_mobile_login_has_a_separate_public_limit(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/tokeninfo*' => Http::response([
                'email' => 's.google-rate@usm.edu.ph',
                'email_verified' => 'true',
                'name' => 'Google Rate User',
                'sub' => 'google-rate-sub',
                'aud' => '436328333223-lmb41qei92hv5i84fk0596can1og40jl.apps.googleusercontent.com',
            ]),
        ]);
        config(['services.eaes.rate_limits.google_login_per_minute' => 10]);

        $payload = [
            'id_token' => 'google-rate-token',
            'device_name' => 'google-rate-device',
        ];

        foreach (range(1, 10) as $attempt) {
            $this->postJson('/api/v1/auth/google/mobile', $payload)->assertOk();
        }

        $this->postJson('/api/v1/auth/google/mobile', $payload)
            ->assertTooManyRequests()
            ->assertJsonPath('success', false)
            ->assertJsonPath('retry_after', fn (mixed $value): bool => is_int($value));
    }

    public function test_authenticated_limit_is_scoped_to_user_and_ip(): void
    {
        config(['services.eaes.rate_limits.authenticated_per_minute' => 1]);
        $firstUser = $this->createApiUser('rate-first@usm.edu.ph');
        $secondUser = $this->createApiUser('rate-second@usm.edu.ph');

        Sanctum::actingAs($firstUser);
        $this->getJson('/api/user')->assertOk();
        $this->getJson('/api/user')->assertTooManyRequests();

        Sanctum::actingAs($secondUser);
        $this->getJson('/api/user')->assertOk();
    }

    public function test_nlp_query_uses_the_stricter_authenticated_limiter(): void
    {
        config(['services.eaes.rate_limits.nlp_per_minute' => 1]);
        $admin = $this->createApiUser('rate-nlp@usm.edu.ph', ['Society Admin']);
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/admin/nlp-query', [])->assertUnprocessable();
        $this->postJson('/api/v1/admin/nlp-query', [])->assertTooManyRequests();
    }

    public function test_api_routes_have_the_expected_rate_limit_layers(): void
    {
        $routeMiddleware = static function (string $uri, string $method = 'GET'): array {
            return Route::getRoutes()
                ->match(Request::create('/'.$uri, $method))
                ->gatherMiddleware();
        };

        $this->assertLimiterAttached('api-login', $routeMiddleware('api/v1/auth/login', 'POST'));
        $this->assertLimiterAttached('api-google-login', $routeMiddleware('api/v1/auth/google/mobile', 'POST'));
        $this->assertLimiterAttached('api-authenticated', $routeMiddleware('api/v1/events'));
        $this->assertLimiterAttached('api-write', $routeMiddleware('api/v1/events', 'POST'));
        $this->assertLimiterAttached('api-scanner-sync', $routeMiddleware('api/v1/attendance/sync', 'POST'));
        $this->assertLimiterAttached('api-nlp', $routeMiddleware('api/v1/admin/nlp-query', 'POST'));
    }

    /**
     * @param  array<int, string>  $roles
     */
    private function createApiUser(string $email, array $roles = []): User
    {
        $user = User::create([
            'name' => 'Rate Limit User',
            'email' => $email,
            'password' => Hash::make('password'),
            'user_type' => 'faculty_or_staff_pending',
        ]);

        if ($roles !== []) {
            $user->assignRole($roles);
        }

        return $user;
    }

    /**
     * @param  array<int, string>  $middleware
     */
    private function assertLimiterAttached(string $limiter, array $middleware): void
    {
        $this->assertTrue(collect($middleware)->contains(
            fn (string $entry): bool => str_contains($entry, $limiter)
        ), "Expected {$limiter} middleware to be attached.");
    }
}
