<x-layouts.guest title="USM EAES Sign In">
    <div class="auth-gateway" aria-label="USM EAES sign in gateway">
        <section class="auth-overview" aria-labelledby="auth-overview-title">
            <div class="auth-overview-kicker">University of Southern Mindanao</div>
            <h1 id="auth-overview-title" class="auth-overview-title font-display">Event Attendance & Evaluation System</h1>
            <p class="auth-overview-copy">
                One institutional login for attendance, proposals, scanning, and evaluations.
            </p>

            <div class="auth-access-list" aria-label="EAES access areas">
                <div class="auth-access-row">
                    <span class="auth-access-icon icon-student" aria-hidden="true">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M16 14a4 4 0 10-8 0m8 0a6 6 0 00-8 0m8 0v1a2 2 0 01-2 2h-4a2 2 0 01-2-2v-1m10 5H6a2 2 0 01-2-2V9a2 2 0 012-2h2m8 0h2a2 2 0 012 2v8a2 2 0 01-2 2z"/></svg>
                    </span>
                    <span>
                        <strong>Students</strong>
                        <span>Profile, attendance history, and evaluations</span>
                    </span>
                </div>
                <div class="auth-access-row">
                    <span class="auth-access-icon icon-admin" aria-hidden="true">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M8 7V3m8 4V3M5 11h14M6 21h12a2 2 0 002-2V7a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                    </span>
                    <span>
                        <strong>Event admins</strong>
                        <span>Proposals, attendance operations, and exports</span>
                    </span>
                </div>
                <div class="auth-access-row">
                    <span class="auth-access-icon icon-hr" aria-hidden="true">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 12l2 2 4-4m5.5-4.5A12 12 0 0112 3 12 12 0 013.5 5.5 12.5 12.5 0 003 9c0 5.4 3.7 10.1 9 11.5 5.3-1.4 9-6.1 9-11.5 0-1.2-.2-2.3-.5-3.5z"/></svg>
                    </span>
                    <span>
                        <strong>OSA and HR</strong>
                        <span>Governance, review queues, and scoped records</span>
                    </span>
                </div>
            </div>

            <div class="auth-scanner-note">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                <span>Scanner access is granted only through assigned mobile scanner sessions.</span>
            </div>

            <p class="auth-footer text-xs mt-12 text-left">
                &copy; {{ date('Y') }} USM EAES - Office of Student Affairs
            </p>
        </section>

        <section class="card auth-card" aria-labelledby="auth-title">
            {{-- Header / Branding --}}
            <div class="card-body text-center pb-2">
                <div class="auth-brand-mark">
                    <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                </div>
                <h2 id="auth-title" class="auth-title font-display">USM EAES</h2>
                <p class="auth-subtitle font-semibold text-slate-800">Secure institutional sign in</p>
                <p class="auth-school text-slate-500">Use your official USM Google account</p>
            </div>

            <div class="px-6 pb-6">
                {{-- Error Flash --}}
                @if(session('error'))
                <div class="mb-4 p-3 rounded-lg text-sm flex items-start gap-2" style="background-color: #fee2e2; color: #dc2626;" role="alert">
                    <svg class="w-5 h-5 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <span>{{ session('error') }}</span>
                </div>
                @endif

                {{-- Google OAuth Button --}}
                <a href="{{ route('auth.google') }}"
                   id="google-login-btn"
                   class="auth-google-btn w-full btn btn-lg justify-center gap-3 font-medium">
                    <svg class="w-5 h-5 shrink-0" viewBox="0 0 24 24" aria-hidden="true">
                        <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 01-2.2 3.32v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.1z" fill="#4285F4"/>
                        <path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/>
                        <path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05"/>
                        <path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/>
                    </svg>
                    Continue with USM Google Account
                </a>

                <div class="auth-domain-note">
                    <p class="text-xs">
                        <svg class="w-3.5 h-3.5 inline-block mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        Access is restricted to <strong>@usm.edu.ph</strong> accounts
                    </p>
                </div>

                <div class="auth-security-note">
                    Attendance and evaluation records are protected under institutional account access.
                </div>
            </div>
        </section>
    </div>
</x-layouts.guest>
