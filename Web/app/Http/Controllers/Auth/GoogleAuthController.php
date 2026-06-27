<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Illuminate\Support\Str;

class GoogleAuthController extends Controller
{
    /**
     * Redirect the user to the Google authentication page.
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function redirectToGoogle()
    {
        return Socialite::driver('google')->redirect();
    }

    /**
     * Obtain the user information from Google.
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function handleGoogleCallback()
    {
        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (\Exception $e) {
            return redirect('/login')->with('error', 'Google authentication failed.');
        }

        $email = $googleUser->getEmail();
        $allowedDomain = env('EAES_ALLOWED_EMAIL_DOMAIN', 'usm.edu.ph');

        // Enforce institutional domain constraint
        if (!Str::endsWith($email, '@' . $allowedDomain)) {
            return redirect('/login')->with('error', "Access restricted to institutional @{$allowedDomain} accounts.");
        }

        // Find or create user
        $user = User::where('email', $email)->first();

        if (!$user) {
            $user = User::create([
                'name' => $googleUser->getName(),
                'email' => $email,
                'google_sub' => $googleUser->getId(),
                'password' => null, // Password is null since they authenticate via Google OAuth
            ]);

            // Assign default Student role on onboarding
            $user->assignRole('Student');
        } else {
            // Update Google Sub ID if not set
            if (empty($user->google_sub)) {
                $user->google_sub = $googleUser->getId();
                $user->save();
            }
        }

        Auth::login($user);

        // Check if user has an admin role and redirect to dashboard, else student portal
        if ($user->hasAnyRole(['Super Admin (OSA)', 'USG Admin', 'LSG Admin', 'Society Admin', 'ARO Admin'])) {
            return redirect('/dashboard');
        }

        return redirect('/portal');
    }
}
