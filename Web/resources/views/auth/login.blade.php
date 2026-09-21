<x-layouts.guest title="Eventia — Sign In">
    <div class="eventia-login" aria-label="Eventia sign in">
        <section class="eventia-login-card" aria-labelledby="auth-title">
            {{-- Gold top accent bar --}}
            <div class="eventia-login-bar" aria-hidden="true"></div>

            <div class="eventia-login-body">
                {{-- Logo --}}
                <div class="eventia-login-brand">
                    <img src="{{ asset('images/eventia-logo.png') }}"
                         alt="Eventia logo"
                         width="72"
                         height="72"
                         class="eventia-login-logo"
                         draggable="false" />
                </div>

                {{-- Heading --}}
                <h1 id="auth-title" class="eventia-login-title font-display">Eventia</h1>
                <p class="eventia-login-subtitle">Event Attendance & Evaluation</p>
                <p class="eventia-login-hint">Use your official USM Google account</p>

                {{-- Error Flash --}}
                @if(session('error'))
                <div class="eventia-login-error" role="alert">
                    <svg class="w-5 h-5 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <span>{{ session('error') }}</span>
                </div>
                @endif

                {{-- Google OAuth Button --}}
                <a href="{{ route('auth.google') }}"
                   id="google-login-btn"
                   class="eventia-google-btn">
                    <svg class="w-5 h-5 shrink-0" viewBox="0 0 24 24" aria-hidden="true">
                        <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 01-2.2 3.32v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.1z" fill="#4285F4"/>
                        <path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/>
                        <path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05"/>
                        <path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/>
                    </svg>
                    Continue with USM Google Account
                </a>

                {{-- Domain note --}}
                <div class="eventia-domain-note">
                    <p>
                        <svg class="w-3.5 h-3.5 inline-block mr-1 align-[-2px]" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        Access is restricted to <strong>@usm.edu.ph</strong> accounts
                    </p>
                </div>

                {{-- Security note --}}
                <p class="eventia-security-note">
                    Attendance and evaluation records are protected under institutional account access.
                </p>
            </div>

            {{-- Footer --}}
            <div class="eventia-login-footer">
                &copy; {{ date('Y') }} USM &mdash; Office of Student Affairs
            </div>
        </section>
    </div>
</x-layouts.guest>
