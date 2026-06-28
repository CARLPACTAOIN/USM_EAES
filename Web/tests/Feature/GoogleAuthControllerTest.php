<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Contracts\Provider as SocialiteProvider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

class GoogleAuthControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_google_redirect_prompts_account_selection_with_institutional_domain_hint(): void
    {
        config([
            'services.google.client_id' => 'google-client-id',
            'services.google.client_secret' => 'google-client-secret',
            'services.google.redirect' => 'http://localhost/auth/google/callback',
            'services.eaes.allowed_email_domain' => 'usm.edu.ph',
        ]);

        $response = $this->get(route('auth.google'));

        $response->assertRedirect();

        $location = $response->headers->get('Location');
        $this->assertIsString($location);
        $this->assertStringContainsString('accounts.google.com', $location);

        parse_str(parse_url($location, PHP_URL_QUERY) ?: '', $query);

        $this->assertSame('select_account', $query['prompt'] ?? null);
        $this->assertSame('usm.edu.ph', $query['hd'] ?? null);
    }

    public function test_google_callback_rejects_non_institutional_email_without_creating_session_or_user(): void
    {
        config(['services.eaes.allowed_email_domain' => 'usm.edu.ph']);

        $googleUser = (new SocialiteUser)->map([
            'id' => 'google-user-id',
            'name' => 'Personal Gmail User',
            'email' => 'personal@gmail.com',
        ]);

        $provider = Mockery::mock(SocialiteProvider::class);
        $provider->shouldReceive('user')->once()->andReturn($googleUser);

        Socialite::shouldReceive('driver')->once()->with('google')->andReturn($provider);

        $response = $this->get(route('auth.google.callback'));

        $response
            ->assertRedirect(route('login'))
            ->assertSessionHas('error', 'Access restricted to institutional @usm.edu.ph accounts.');

        $this->assertGuest();
        $this->assertDatabaseMissing(User::class, ['email' => 'personal@gmail.com']);
    }
}
