<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\Response;

class GoogleAuthController extends Controller
{
    /**
     * Redirect the user to the Google authentication page.
     *
     * @return Response
     */
    public function redirectToGoogle()
    {
        $allowedDomain = $this->allowedEmailDomain();

        return Socialite::driver('google')
            ->with([
                'prompt' => 'select_account',
                'hd' => $allowedDomain,
            ])
            ->redirect();
    }

    /**
     * Obtain the user information from Google.
     *
     * @return RedirectResponse
     */
    public function handleGoogleCallback(Request $request)
    {
        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (\Exception $e) {
            return redirect()->route('login')->with('error', 'Google authentication failed.');
        }

        $email = Str::lower((string) $googleUser->getEmail());
        $allowedDomain = $this->allowedEmailDomain();

        // Enforce institutional domain constraint
        if (! Str::endsWith($email, '@'.$allowedDomain)) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('error', "Access restricted to institutional @{$allowedDomain} accounts.");
        }

        // Find or create user
        $user = User::where('email', $email)->first();

        if (! $user) {
            $user = User::create([
                'name' => $googleUser->getName(),
                'email' => $email,
                'google_sub' => $googleUser->getId(),
                'password' => null, // Password is null since they authenticate via Google OAuth
                'user_type' => $this->userTypeForEmail($email),
            ]);

            $user->assignRole($user->user_type === 'student' ? 'Student' : 'Faculty');
        } else {
            // Update Google Sub ID if not set
            $updates = [];
            if (empty($user->google_sub)) {
                $updates['google_sub'] = $googleUser->getId();
            }
            if (! $user->user_type) {
                $updates['user_type'] = $this->userTypeForEmail($email);
            }
            if ($updates !== []) {
                $user->forceFill($updates)->save();
            }
        }

        Auth::login($user);

        // Check if user has an admin role and redirect to dashboard, else student portal
        if ($user->hasAnyRole(['Super Admin (OSA)', 'USG Admin', 'LSG Admin', 'Society Admin', 'Academic Unit Admin', 'Office Event Admin', 'HR Admin'])) {
            return redirect('/dashboard');
        }

        if (! $user->hasRole('Student')) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('error', 'Faculty and staff portal access is pending HR roster activation.');
        }

        return redirect('/portal');
    }

    private function allowedEmailDomain(): string
    {
        return Str::lower(ltrim((string) config('services.eaes.allowed_email_domain', 'usm.edu.ph'), '@'));
    }

    private function userTypeForEmail(string $email): string
    {
        return Str::startsWith(Str::lower($email), 's.')
            ? 'student'
            : 'faculty_or_staff_pending';
    }
}
