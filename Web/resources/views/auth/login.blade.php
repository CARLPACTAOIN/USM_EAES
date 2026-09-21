<x-layouts.guest title="Eventia — Sign In">
    <div class="eventia-landing-wrap" aria-label="Eventia platform gateway">
        <div class="eventia-landing-grid">

            {{-- Left Panel: Product Branding & Capabilities --}}
            <section class="eventia-brand-panel" aria-labelledby="brand-title">
                {{-- Logo and Institution Badge Lockup --}}
                <div class="eventia-brand-header">
                    <div class="eventia-logo-wrap">
                        <img src="{{ asset('images/eventia-logo.png') }}"
                             alt="Eventia emblem"
                             width="84"
                             height="84"
                             class="eventia-brand-logo"
                             draggable="false" />
                    </div>
                    <div class="eventia-inst-badge">
                        <span class="eventia-inst-dot" aria-hidden="true"></span>
                        <span>University of Southern Mindanao</span>
                    </div>
                </div>

                {{-- Display Title & Tagline --}}
                <div class="eventia-brand-hero">
                    <div class="eventia-title-lockup">
                        <h1 id="brand-title" class="eventia-brand-title font-display">
                            <span class="eventia-title-gradient">Eventia</span>
                            <span class="eventia-title-sparkle" aria-hidden="true">✦</span>
                        </h1>
                        <div class="eventia-title-glow-line" aria-hidden="true"></div>
                    </div>
                    <p class="eventia-brand-tagline">
                        Seamless Attendance. Instant Verification. Effortless Evaluation.
                    </p>
                    <p class="eventia-brand-desc">
                        The central digital gateway for the USM Event Attendance & Evaluation System &mdash; managing student activities, institutional clearances, and real-time attendance analytics.
                    </p>
                </div>

                {{-- Feature Highlights --}}
                <div class="eventia-features" role="list" aria-label="Key Capabilities">
                    <div class="eventia-feature-card" role="listitem">
                        <div class="eventia-feature-icon" aria-hidden="true">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z"/></svg>
                        </div>
                        <div class="eventia-feature-content">
                            <span class="eventia-feature-title">Fast QR Ingest</span>
                            <span class="eventia-feature-desc">High-speed check-ins with tamper-resistant scan validation.</span>
                        </div>
                    </div>

                    <div class="eventia-feature-card" role="listitem">
                        <div class="eventia-feature-icon" aria-hidden="true">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                        </div>
                        <div class="eventia-feature-content">
                            <span class="eventia-feature-title">Offline Resilience</span>
                            <span class="eventia-feature-desc">Reliable mobile sync queue designed for campus networks.</span>
                        </div>
                    </div>

                    <div class="eventia-feature-card" role="listitem">
                        <div class="eventia-feature-icon" aria-hidden="true">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                        </div>
                        <div class="eventia-feature-content">
                            <span class="eventia-feature-title">Automated Clearance</span>
                            <span class="eventia-feature-desc">Integrated post-event evaluation gating and instant status.</span>
                        </div>
                    </div>
                </div>
            </section>

            {{-- Right Panel: Frosted Glass Login Card --}}
            <section class="eventia-auth-panel" aria-labelledby="auth-card-title">
                <div class="eventia-glass-card">
                    {{-- Gold top accent bar --}}
                    <div class="eventia-card-bar" aria-hidden="true"></div>

                    <div class="eventia-card-body">
                        {{-- Card Header --}}
                        <div class="eventia-card-header">
                            <h2 id="auth-card-title" class="eventia-card-title">Sign In to Eventia</h2>
                            <p class="eventia-card-subtitle">Access your attendance, events, and evaluations</p>
                        </div>

                        {{-- Error Flash --}}
                        @if(session('error'))
                        <div class="eventia-card-error" role="alert">
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
                            <span>Continue with USM Google Account</span>
                        </a>

                        {{-- Domain note --}}
                        <div class="eventia-domain-pill">
                            <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            <span>Access restricted to <strong>@usm.edu.ph</strong> accounts</span>
                        </div>

                        {{-- Security note --}}
                        <p class="eventia-card-security">
                            Protected under USM Institutional Access Control &amp; Data Privacy Policy.
                        </p>
                    </div>

                    {{-- Footer --}}
                    <div class="eventia-card-footer">
                        <span>&copy; {{ date('Y') }} USM Office of Student Affairs</span>
                    </div>
                </div>
            </section>

        </div>
    </div>
</x-layouts.guest>
