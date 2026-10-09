<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Eventia — USM Event Attendance & Evaluation System' }}</title>
    <meta name="description" content="Eventia — University of Southern Mindanao Event Attendance and Evaluation System">
    <link rel="icon" type="image/png" href="{{ asset('images/eventia-logo.png') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Fira+Code:wght@400;500;600&display=swap" rel="stylesheet">
    @if(request()->routeIs('portal.profile*'))
        <script src="{{ asset('vendor/html5-qrcode/html5-qrcode.min.js') }}"></script>
        <script>
            document.addEventListener('alpine:init', () => {
                window.Alpine.data('dependentSelect', (orgsJson = '{}', programsJson = '{}', initialCollege = '', initialProgram = '', initialOrg = '') => ({
                    allOrgs: typeof orgsJson === 'string' ? JSON.parse(orgsJson) : orgsJson,
                    allPrograms: typeof programsJson === 'string' ? JSON.parse(programsJson) : programsJson,
                    selectedCollege: initialCollege || '',
                    selectedProgram: initialProgram || '',
                    selectedOrg: initialOrg || '',
                    filteredOrgs: [],
                    filteredPrograms: [],
                    init() {
                        this.filteredOrgs = this.allOrgs[this.selectedCollege] || [];
                        this.filteredPrograms = this.allPrograms[this.selectedCollege] || [];
                        this.$watch('selectedCollege', (value) => {
                            this.filteredOrgs = this.allOrgs[value] || [];
                            this.filteredPrograms = this.allPrograms[value] || [];
                            this.selectedProgram = '';
                            this.selectedOrg = '';
                        });
                    }
                }));

                window.Alpine.data('profileQrScanner', (initialValue = '') => ({
                    mode: 'scanner',
                    scannedValue: initialValue || '',
                    scanning: false,
                    cameraError: '',
                    readerId: '',
                    scanner: null,
                    init() {
                        this.readerId = `qr-reader-${Math.random().toString(36).slice(2)}`;
                        this.$nextTick(() => {
                            if (this.$refs.reader) {
                                this.$refs.reader.id = this.readerId;
                            }
                        });
                    },
                    async startScanner() {
                        this.cameraError = '';
                        if (!this.readerId || !window.Html5Qrcode) {
                            this.cameraError = 'Camera scanner is unavailable. Use manual entry instead.';
                            this.mode = 'manual';
                            return;
                        }

                        this.scanner = new window.Html5Qrcode(this.readerId);

                        try {
                            await this.scanner.start(
                                { facingMode: 'environment' },
                                { fps: 10, qrbox: { width: 250, height: 250 } },
                                async (decodedText) => {
                                    this.scannedValue = decodedText;
                                    await this.stopScanner();
                                },
                                () => {}
                            );
                            this.scanning = true;
                        } catch (error) {
                            this.cameraError = 'Camera access failed. Use manual entry instead.';
                            this.mode = 'manual';
                            this.scanning = false;
                        }
                    },
                    async stopScanner() {
                        if (!this.scanner) {
                            this.scanning = false;
                            return;
                        }

                        try {
                            await this.scanner.stop();
                            await this.scanner.clear();
                        } catch (error) {
                            // html5-qrcode throws if stop runs before a stream fully starts.
                        }

                        this.scanner = null;
                        this.scanning = false;
                    }
                }));
            });
        </script>
    @endif
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <link rel="stylesheet" href="{{ asset('css/site-refresh.css') }}">
    @if(request()->routeIs('dashboard.events*') || request()->routeIs('dashboard.venues*') || request()->routeIs('dashboard.venue-availability*') || request()->routeIs('dashboard.admin-users*') || request()->routeIs('dashboard.admin-access*') || request()->routeIs('dashboard.analytics*') || request()->routeIs('portal.profile*') || request()->routeIs('dashboard.proposal-process-templates*'))
        <link rel="stylesheet" href="{{ asset('css/proposal-modal.css') }}">
    @endif
    @livewireStyles
    @stack('styles')
</head>
<body class="eaes-app-body min-h-dvh bg-(--color-surface-raised)" x-data="sidebar">
    @php
        $isAdmin = auth()->user()?->hasAnyRole(['Super Admin (OSA)', 'USG Admin', 'LSG Admin', 'Society Admin', 'Academic Unit Admin', 'Office Event Admin', 'HR Admin']);
        $hasStudentPortal = auth()->user()?->hasRole('Student');
        $notificationSummary = $notificationSummary ?? ['unread_count' => 0, 'needs_action_count' => 0];
        $unreadNotificationCount = (int) ($notificationSummary['unread_count'] ?? 0);
        $unreadNotificationLabel = $unreadNotificationCount > 99 ? '99+' : (string) $unreadNotificationCount;
        $canManageOrganizationProfile = $currentAdminScope
            && !$currentAdminScope->isSuperAdmin()
            && $currentAdminScope->organization;
    @endphp

    {{-- ─── Mobile Top Bar ─────────────────────────────────── --}}
    <header class="mobile-topbar lg:hidden fixed top-0 left-0 right-0 z-50 h-14 flex items-center justify-between px-4 bg-(--color-sidebar) text-white">
        <button @click="toggle()" class="btn-icon text-white" aria-label="Toggle navigation">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
        </button>
        <div class="flex items-center gap-2">
            <img src="{{ asset('images/eventia-logo.png') }}" alt="Eventia emblem" class="w-7 h-7 object-contain" />
            <span class="font-display text-sm font-bold tracking-tight">Eventia</span>
        </div>
        <div class="w-8 h-8 rounded-full bg-(--color-sidebar-active) flex items-center justify-center text-xs font-semibold">
            {{ substr(auth()->user()->name ?? 'U', 0, 1) }}
        </div>
    </header>

    {{-- ─── Mobile Overlay ─────────────────────────────────── --}}
    <div x-show="open" @click="close()" class="lg:hidden fixed inset-0 z-50 bg-black/50" x-transition.opacity x-cloak></div>

    {{-- ─── Sidebar ────────────────────────────────────────── --}}
    <aside class="sidebar" :class="{ 'open': open }">
        <div class="p-5 border-b border-white/10">
            <div class="eaes-brand">
                <span class="eaes-brand-mark" aria-hidden="true">
                    <img src="{{ asset('images/eventia-logo.png') }}" alt="Eventia emblem" class="w-7 h-7 object-contain" />
                </span>
                <div>
                    <h1 class="eaes-brand-title">Eventia</h1>
                    <p class="eaes-brand-subtitle">Event Attendance & Evaluation</p>
                </div>
            </div>
        </div>

        @if(($authorityContexts ?? collect())->count() > 1)
            <form method="POST" action="{{ route('authority-context.store') }}" class="space-y-2 border-b border-white/10 p-3">
                @csrf
                <label for="sidebar-authority-context" class="block px-1 text-xs font-semibold uppercase tracking-wider text-(--color-sidebar-text)">Active authority</label>
                <select id="sidebar-authority-context" name="assignment_id" class="w-full rounded-md border border-white/15 bg-white/10 px-3 py-2 text-xs text-white focus:border-white/40 focus:outline-none focus:ring-2 focus:ring-white/20" required>
                    <option value="" class="text-slate-900">Select organization</option>
                    @foreach($authorityContexts as $assignment)
                        <option value="{{ $assignment->id }}" class="text-slate-900" @selected($currentAdminScope?->assignment?->id === $assignment->id)>
                            {{ $assignment->organization?->acronym ?? $assignment->organization?->name ?? $assignment->role_name }} · {{ $assignment->position_title ?? $assignment->role_name }}
                        </option>
                    @endforeach
                </select>
                <button type="submit" class="w-full rounded-md bg-white/10 px-3 py-2 text-xs font-semibold text-white transition-colors hover:bg-white/15 focus:outline-none focus:ring-2 focus:ring-white/30">Use this context</button>
            </form>
        @endif

        <nav class="p-3 space-y-1">
            @if($isAdmin)
                {{-- Admin Navigation --}}
                <p class="px-3 pt-4 pb-1 text-xs font-semibold text-(--color-sidebar-text) uppercase">Dashboard</p>

                <a href="{{ route('dashboard') }}"
                   class="sidebar-link relative {{ request()->routeIs('dashboard') && !request()->routeIs('dashboard.*') ? 'sidebar-link-active' : '' }}">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
                    <span class="flex-1">Overview</span>
                    @livewire('dashboard.notification-sidebar-badge', ['type' => 'unread'], key('sidebar-notification-unread'))
                </a>

                <a href="{{ route('dashboard.events') }}"
                   class="sidebar-link relative {{ request()->routeIs('dashboard.events*') ? 'sidebar-link-active' : '' }}">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                    <span class="flex-1">Event Proposals</span>
                    @livewire('dashboard.notification-sidebar-badge', ['type' => 'needs_action'], key('sidebar-notification-needs-action'))
                </a>

                <a href="{{ route('dashboard.workflow-tasks.index') }}"
                   class="sidebar-link relative {{ request()->routeIs('dashboard.workflow-tasks*') ? 'sidebar-link-active' : '' }}">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 6.75h11.25M9 12h11.25M9 17.25h11.25M4.5 6.75l1.5 1.5 2.25-3M4.5 12l1.5 1.5 2.25-3M4.5 17.25l1.5 1.5 2.25-3"/></svg>
                    <span class="flex-1">Workflow Tasks</span>
                </a>

                @if(auth()->user()?->can('create-proposals'))
                <a href="{{ route('dashboard.venue-availability') }}"
                   class="sidebar-link relative {{ request()->routeIs('dashboard.venue-availability*') ? 'sidebar-link-active' : '' }}">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 20h16M6 20V8l6-4 6 4v12M9 12h6m-6 4h6"/></svg>
                    Venue Availability
                </a>
                @endif

                <a href="{{ route('dashboard.pending-links') }}"
                   class="sidebar-link relative {{ request()->routeIs('dashboard.pending-links*') ? 'sidebar-link-active' : '' }}">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/></svg>
                    Pending QR Links
                </a>

                <a href="{{ route('dashboard.ai') }}"
                   class="sidebar-link relative {{ request()->routeIs('dashboard.ai*') ? 'sidebar-link-active' : '' }}">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.75 3.75L8.25 7.5 4.5 9l3.75 1.5 1.5 3.75 1.5-3.75L15 9l-3.75-1.5-1.5-3.75zM17.25 12l-.9 2.25L14.1 15l2.25.75.9 2.25.9-2.25L20.4 15l-2.25-.75-.9-2.25z"/></svg>
                    AI Insights
                </a>

                <a href="{{ route('dashboard.analytics') }}"
                   class="sidebar-link relative {{ request()->routeIs('dashboard.analytics*') ? 'sidebar-link-active' : '' }}">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                    Analytics
                </a>

                @if($canManageOrganizationProfile)
                <a href="{{ route('dashboard.organization-profile') }}"
                   class="sidebar-link relative {{ request()->routeIs('dashboard.organization-profile*') ? 'sidebar-link-active' : '' }}">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3.75 21h16.5M4.5 3h15l-.75 18H5.25L4.5 3zm3.75 4.5h7.5m-7.5 4h7.5m-7.5 4h4"/></svg>
                    Organization Profile
                </a>
                @endif

                @if(auth()->user()?->hasRole('Super Admin (OSA)'))
                <a href="{{ route('dashboard.venues') }}"
                   class="sidebar-link relative {{ request()->routeIs('dashboard.venues*') ? 'sidebar-link-active' : '' }}">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 20h16M6 20V8l6-4 6 4v12M9 12h6m-6 4h6"/></svg>
                    Venue Catalog
                </a>
                <a href="{{ route('dashboard.proposal-process-templates.index') }}"
                   class="sidebar-link relative {{ request()->routeIs('dashboard.proposal-process-templates*') || request()->routeIs('dashboard.proposal-process-template-versions*') ? 'sidebar-link-active' : '' }}">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 6.75h11.25M9 12h11.25M9 17.25h11.25M3.75 6.75h.008v.008H3.75V6.75zm0 5.25h.008v.008H3.75V12zm0 5.25h.008v.008H3.75v-.008z"/></svg>
                    Workflow Templates
                </a>
                <a href="{{ route('dashboard.admin-users') }}"
                   class="sidebar-link relative {{ request()->routeIs('dashboard.admin-users*') || request()->routeIs('dashboard.admin-access*') ? 'sidebar-link-active' : '' }}">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                    Admin Access
                </a>
                @endif

                @if($hasStudentPortal)
                    <p class="px-3 pt-5 pb-1 text-xs font-semibold text-(--color-sidebar-text) uppercase">Student Portal</p>

                    <a href="{{ route('portal') }}"
                       class="sidebar-link relative {{ request()->routeIs('portal') && !request()->routeIs('portal.*') ? 'sidebar-link-active' : '' }}">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
                        Portal Home
                    </a>

                    <a href="{{ route('portal.profile') }}"
                       class="sidebar-link relative {{ request()->routeIs('portal.profile*') ? 'sidebar-link-active' : '' }}">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                        My Profile
                    </a>

                    <a href="{{ route('portal.evaluations') }}"
                       class="sidebar-link relative {{ request()->routeIs('portal.evaluations') || request()->routeIs('portal.evaluation') ? 'sidebar-link-active' : '' }}">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5h6m-7 4h8m-9 4h5m-6 8h12a2 2 0 0 0 2-2V7.5L14.5 2H6a2 2 0 0 0-2 2v15a2 2 0 0 0 2 2z"/></svg>
                        Evaluations
                    </a>
                @endif
            @else
                {{-- Student Navigation --}}
                <p class="px-3 pt-4 pb-1 text-xs font-semibold text-(--color-sidebar-text) uppercase">Portal</p>

                <a href="{{ route('portal') }}"
                   class="sidebar-link relative {{ request()->routeIs('portal') && !request()->routeIs('portal.*') ? 'sidebar-link-active' : '' }}">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
                    Home
                </a>

                <a href="{{ route('portal.profile') }}"
                   class="sidebar-link relative {{ request()->routeIs('portal.profile*') ? 'sidebar-link-active' : '' }}">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                    My Profile
                </a>

                <a href="{{ route('portal.evaluations') }}"
                   class="sidebar-link relative {{ request()->routeIs('portal.evaluations') || request()->routeIs('portal.evaluation') ? 'sidebar-link-active' : '' }}">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5h6m-7 4h8m-9 4h5m-6 8h12a2 2 0 0 0 2-2V7.5L14.5 2H6a2 2 0 0 0-2 2v15a2 2 0 0 0 2 2z"/></svg>
                    Evaluations
                </a>
            @endif
        </nav>

        {{-- User Info + Logout --}}
        <div class="eaes-user-dock absolute bottom-0 left-0 right-0 p-3 border-t border-white/10">
            <div class="flex items-center gap-3 px-3 py-2">
                <div class="eaes-avatar w-8 h-8 rounded-full bg-(--color-sidebar-active) flex items-center justify-center text-sm font-semibold text-white shrink-0">
                    {{ substr(auth()->user()->name ?? 'U', 0, 1) }}
                </div>
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-medium text-white truncate">{{ auth()->user()->name ?? 'User' }}</p>
                    <p class="text-xs text-(--color-sidebar-text) truncate">{{ auth()->user()->email ?? '' }}</p>
                </div>
            </div>
            <button type="button"
                    onclick="document.getElementById('sign-out-confirm-modal')?.showModal()"
                    class="sidebar-link w-full text-left text-red-400 hover:text-red-300 hover:bg-red-500/10 mt-2">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                Sign Out
            </button>
        </div>
    </aside>

    <dialog id="sign-out-confirm-modal"
            class="confirm-modal"
            aria-labelledby="sign-out-confirm-title"
            aria-describedby="sign-out-confirm-description"
            onclick="if (event.target === this) this.close()">
        <div class="confirm-modal-panel">
            <div class="confirm-modal-header">
                <span class="confirm-modal-icon" aria-hidden="true">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                </span>
                <div>
                    <h2 id="sign-out-confirm-title" class="confirm-modal-title">Sign out?</h2>
                    <p id="sign-out-confirm-description" class="confirm-modal-description">Your current session will end on this device.</p>
                </div>
            </div>
            <form method="POST" action="{{ route('logout') }}" class="confirm-modal-actions">
                @csrf
                <button type="button" onclick="document.getElementById('sign-out-confirm-modal')?.close()" class="btn btn-secondary">Cancel</button>
                <button type="submit" class="btn btn-destructive">Sign Out</button>
            </form>
        </div>
    </dialog>

    {{-- ─── Main Content ───────────────────────────────────── --}}
    <main class="main-content min-h-dvh pt-14 lg:pt-0">
        {{-- Flash Messages --}}
        @if(session('success'))
        <div class="toast toast-success" x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 4000)" x-transition x-cloak role="alert">
            <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <span class="text-sm">{{ session('success') }}</span>
            <button @click="show = false" class="ml-auto" aria-label="Dismiss"><svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/></svg></button>
        </div>
        @endif

        @if(session('error'))
        <div class="toast toast-error" x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 5000)" x-transition x-cloak role="alert">
            <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <span class="text-sm">{{ session('error') }}</span>
            <button @click="show = false" class="ml-auto" aria-label="Dismiss"><svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/></svg></button>
        </div>
        @endif

        <div class="eaes-page-shell p-4 lg:p-8">
            {{ $slot }}
        </div>
    </main>

    @livewireScriptConfig
    @stack('scripts')
</body>
</html>
