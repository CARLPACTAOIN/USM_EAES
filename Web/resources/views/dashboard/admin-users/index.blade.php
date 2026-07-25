<x-layouts.app :title="'Admin Access - EAES'">
    @php
        $assignmentOrganizations = $activeOrganizations->map(fn ($org) => [
            'id' => $org->id,
            'name' => $org->name,
            'acronym' => $org->acronym,
            'type' => $org->type,
            'college_id' => $org->college_id,
            'college_code' => $org->college?->code,
        ])->values();

        $assignmentColleges = $colleges->map(fn ($college) => [
            'id' => $college->id,
            'code' => $college->code,
            'name' => $college->name,
        ])->values();

        $collegePrograms = $colleges->map(fn ($college) => [
            'id' => $college->id,
            'code' => $college->code,
            'programs' => $college->programs->map(fn ($program) => [
                'id' => $program->id,
                'code' => $program->code,
                'name' => $program->name,
            ])->values(),
        ])->values();
    @endphp

    <div class="admin-access-page">
        <header class="admin-access-hero">
            <div class="admin-access-hero-main">
                <div class="admin-access-assign-icon" aria-hidden="true">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z"/>
                    </svg>
                </div>
                <div>
                    <p class="portal-page-kicker">OSA workspace</p>
                    <h1 class="text-2xl font-display text-(--color-text-primary)">Admin Access</h1>
                    <p class="text-sm text-(--color-text-secondary) mt-1 max-w-2xl">Manage official organizations and term-bound admin authority from one controlled surface.</p>
                </div>
            </div>
            <div class="admin-access-hero-actions">
                <button type="button"
                        onclick="document.getElementById('assign-admin-modal')?.showModal()"
                        class="btn btn-primary cursor-pointer">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 4v16m8-8H4"/></svg>
                    Assign Admin
                </button>
                <button type="button" onclick="document.getElementById('create-org-modal')?.showModal()" class="btn btn-secondary cursor-pointer">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0H5m14 0h2m-2 0h-4m-5 0H3m2 0h4M9 7h6m-6 4h6m-6 4h6"/></svg>
                    New Organization
                </button>
            </div>
        </header>

        <div class="admin-access-metrics" aria-label="Admin access summary">
            <article class="admin-access-metric admin-access-metric--assignments">
                <div class="admin-access-metric-top">
                    <span class="admin-access-metric-icon" aria-hidden="true">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z"/>
                        </svg>
                    </span>
                    <span class="admin-access-metric-label">Active Assignments</span>
                </div>
                <div class="admin-access-metric-main">
                    <strong class="admin-access-metric-value">{{ $stats['active_assignments'] }}</strong>
                    <span class="admin-access-metric-badge">Term-bound</span>
                </div>
                <span class="admin-access-metric-note">{{ $stats['total_assignments'] }} total authority records</span>
            </article>
            <article class="admin-access-metric admin-access-metric--organizations">
                <div class="admin-access-metric-top">
                    <span class="admin-access-metric-icon" aria-hidden="true">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0H5m14 0h2m-2 0h-4m-5 0H3m2 0h4M9 7h6m-6 4h6m-6 4h6"/>
                        </svg>
                    </span>
                    <span class="admin-access-metric-label">Active Organizations</span>
                </div>
                <div class="admin-access-metric-main">
                    <strong class="admin-access-metric-value">{{ $stats['active_organizations'] }}</strong>
                    <span class="admin-access-metric-badge">Official</span>
                </div>
                <span class="admin-access-metric-note">{{ $stats['total_organizations'] }} official organization records</span>
            </article>
            <article class="admin-access-metric admin-access-metric--roles">
                <div class="admin-access-metric-top">
                    <span class="admin-access-metric-icon" aria-hidden="true">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 6.75a3 3 0 013 3v.75h.75A2.25 2.25 0 0118 12.75v4.5a2.25 2.25 0 01-2.25 2.25h-7.5A2.25 2.25 0 016 17.25v-4.5a2.25 2.25 0 012.25-2.25H9v-.75a3 3 0 013-3zm0 0V4.5m0 0h3m-3 0H9"/>
                        </svg>
                    </span>
                    <span class="admin-access-metric-label">Assignable Roles</span>
                </div>
                <div class="admin-access-metric-main">
                    <strong class="admin-access-metric-value">{{ count($assignableRoles) }}</strong>
                    <span class="admin-access-metric-badge">OSA allowlist</span>
                </div>
                <span class="admin-access-metric-note">USG, LSG, Society, Academic, Office</span>
            </article>
            <article class="admin-access-metric admin-access-metric--audit">
                <div class="admin-access-metric-top">
                    <span class="admin-access-metric-icon" aria-hidden="true">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 6v6l4 2m6-2a10 10 0 11-20 0 10 10 0 0120 0z"/>
                        </svg>
                    </span>
                    <span class="admin-access-metric-label">Audit History</span>
                </div>
                <div class="admin-access-metric-main">
                    <strong class="admin-access-metric-value">{{ $stats['audit_logs'] }}</strong>
                    <span class="admin-access-metric-badge">Logged</span>
                </div>
                <span class="admin-access-metric-note">governance actions available for review</span>
            </article>
        </div>

        {{-- Assignment creation --}}
        @include('dashboard.admin-users.partials.assign-admin-form', [
            'assignmentOrganizations' => $assignmentOrganizations,
            'assignmentColleges' => $assignmentColleges,
            'adminRoles' => $assignableRoles,
        ])

        @livewire('dashboard.admin-access-workspace', [
            'initialTab' => $activeTab,
            'collegePrograms' => $collegePrograms->toArray(),
        ])
    </div>

    {{-- Create organization modal --}}
    <dialog id="create-org-modal"
            class="proposal-modal modal-sm"
            aria-labelledby="create-org-modal-title"
            onclick="if (event.target === this) this.close()"
            x-data="organizationForm({
                colleges: @js($collegePrograms),
                type: @js(old('type', 'society')),
                collegeId: @js(old('college_id', '')),
                programIds: @js(old('program_ids', [])),
            })">
        <form method="POST"
              action="{{ route('dashboard.organizations.store') }}"
              enctype="multipart/form-data"
              class="proposal-modal-form">
            @csrf
            <div class="proposal-modal-header">
                <div class="proposal-modal-title-row">
                    <span class="proposal-modal-icon" aria-hidden="true">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16"/></svg>
                    </span>
                    <div>
                        <p class="proposal-modal-kicker">OSA governance</p>
                        <h2 id="create-org-modal-title" class="proposal-modal-title">Create Organization</h2>
                    </div>
                </div>
                <button type="button" onclick="document.getElementById('create-org-modal').close()" class="btn-icon btn-ghost" aria-label="Close">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <div class="proposal-modal-body space-y-4">
                @include('dashboard.admin-users.partials.organization-fields')
            </div>
            <div class="proposal-modal-footer">
                <div class="proposal-footer-actions">
                    <button type="button" onclick="document.getElementById('create-org-modal').close()" class="btn btn-secondary">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create Organization</button>
                </div>
            </div>
        </form>
    </dialog>

    <script>
        document.addEventListener('alpine:init', () => {
            window.Alpine.data('adminAssignmentForm', (config) => ({
                searchUrl: config.searchUrl,
                organizations: config.organizations,
                colleges: config.colleges,
                role: config.oldRole,
                collegeId: config.oldCollegeId,
                organizationId: config.oldOrganizationId,
                email: config.oldEmail,
                studentId: config.oldStudentId,
                name: config.oldName,
                searchQuery: '',
                results: [],
                showResults: false,
                userLocked: !!(config.oldEmail || config.oldStudentId),
                accountMode: 'search',
                roleTypes: {
                    'USG Admin': 'usg',
                    'LSG Admin': 'lsg',
                    'Society Admin': ['society', 'non_academic_org'],
                    'Academic Unit Admin': 'academic_unit',
                    'Office Event Admin': 'administrative_office',
                },
                async searchUsers() {
                    if (this.searchQuery.length < 2) {
                        this.results = [];
                        this.showResults = false;
                        return;
                    }

                    const response = await fetch(`${this.searchUrl}?q=${encodeURIComponent(this.searchQuery)}`, {
                        headers: { 'Accept': 'application/json' },
                    });

                    this.results = await response.json();
                    this.showResults = this.results.length > 0;
                },
                selectUser(user) {
                    this.email = user.email || '';
                    this.studentId = user.student_id_number || '';
                    this.name = user.name || '';
                    this.searchQuery = user.email || user.student_id_number || user.name;
                    this.userLocked = true;
                    this.accountMode = 'search';
                    this.showResults = false;
                },
                clearUser() {
                    this.email = '';
                    this.studentId = '';
                    this.name = '';
                    this.searchQuery = '';
                    this.userLocked = false;
                    this.results = [];
                },
                setAccountMode(mode) {
                    this.accountMode = mode;
                    this.clearUser();
                    if (mode === 'new') {
                        this.userLocked = false;
                    }
                },
                userInitial() {
                    const source = (this.name || this.email || '?').trim();
                    return source.charAt(0).toUpperCase();
                },
                scopeSummary() {
                    if (this.role === 'USG Admin') {
                        return 'USG organization scope';
                    }
                    if (this.role === 'LSG Admin') {
                        return 'College LSG scope';
                    }
                    if (this.role === 'Society Admin') {
                        return 'Society or non-academic organization scope';
                    }
                    if (this.role === 'Academic Unit Admin') {
                        return 'Academic unit organization scope';
                    }
                    if (this.role === 'Office Event Admin') {
                        return 'Administrative office event scope';
                    }
                    return 'Assignment scope';
                },
                roleUsesCollege() {
                    return ['LSG Admin', 'Academic Unit Admin'].includes(this.role);
                },
                roleUsesOrganization() {
                    return Object.keys(this.roleTypes).includes(this.role);
                },
                filteredOrganizations() {
                    if (!this.roleUsesOrganization()) return [];

                    const targetTypes = Array.isArray(this.roleTypes[this.role])
                        ? this.roleTypes[this.role]
                        : [this.roleTypes[this.role]];
                    return this.organizations.filter((org) => {
                        if (!targetTypes.includes(org.type)) return false;
                        if (this.roleUsesCollege() && this.collegeId) return org.college_id === this.collegeId;
                        if (this.roleUsesCollege() && !this.collegeId) return false;
                        return true;
                    });
                },
                organizationPlaceholder() {
                    if (!this.role) return 'Select role first';
                    if (!this.roleUsesOrganization()) return 'No organization scope';
                    if (this.roleUsesCollege() && !this.collegeId) return 'Select college first';
                    return 'Select organization';
                },
                syncRole() {
                    if (!this.roleUsesCollege()) this.collegeId = '';
                    if (!this.roleUsesOrganization()) {
                        this.organizationId = '';
                        return;
                    }
                    this.syncOrganization();
                },
                syncCollege() {
                    this.syncOrganization();
                },
                syncOrganization() {
                    if (!this.filteredOrganizations().some((org) => org.id === this.organizationId)) {
                        this.organizationId = '';
                    }
                },
                chooseOrganization() {
                    const org = this.organizations.find((item) => item.id === this.organizationId);
                    if (org?.college_id && this.roleUsesCollege()) {
                        this.collegeId = org.college_id;
                    }
                },
                init() {
                    this.syncRole();
                },
            }));

            window.Alpine.data('organizationForm', (config) => ({
                colleges: config.colleges || [],
                type: config.type || 'society',
                collegeId: config.collegeId || '',
                programIds: (config.programIds || []).map((id) => String(id)),
                programSearch: '',
                programsForCollege() {
                    const college = this.colleges.find((item) => item.id === this.collegeId);
                    return college?.programs || [];
                },
                filteredProgramsForCollege() {
                    const term = this.programSearch.trim().toLowerCase();

                    if (!term) {
                        return this.programsForCollege();
                    }

                    return this.programsForCollege().filter((program) => {
                        return [program.code, program.name]
                            .some((value) => String(value || '').toLowerCase().includes(term));
                    });
                },
                selectedProgramCount() {
                    return this.programIds.length;
                },
                selectAllPrograms() {
                    this.programIds = this.programsForCollege().map((program) => String(program.id));
                },
                clearPrograms() {
                    this.programIds = [];
                },
                syncProgramSelection() {
                    if (!this.typeUsesPrograms()) {
                        this.programIds = [];
                        return;
                    }

                    const allowedProgramIds = new Set(this.programsForCollege().map((program) => String(program.id)));
                    this.programIds = this.programIds
                        .map((id) => String(id))
                        .filter((id) => allowedProgramIds.has(id));
                },
                typeShowsCollege() {
                    return ['society', 'non_academic_org', 'lsg', 'academic_unit'].includes(this.type);
                },
                typeRequiresCollege() {
                    return ['society', 'lsg', 'academic_unit'].includes(this.type);
                },
                typeUsesCollege() {
                    return this.typeShowsCollege();
                },
                typeUsesPrograms() {
                    return ['society', 'non_academic_org'].includes(this.type);
                },
                init() {
                    this.syncProgramSelection();

                    this.$watch('collegeId', () => {
                        this.programSearch = '';
                        this.syncProgramSelection();
                    });

                    this.$watch('type', () => {
                        if (!this.typeShowsCollege()) {
                            this.collegeId = '';
                        }

                        this.programSearch = '';
                        this.syncProgramSelection();
                    });
                },
            }));
        });
    </script>

    @if($errors->getBag('default')->any() || $errors->getBag('organization')->any())
        <script>
            document.addEventListener('DOMContentLoaded', () => {
                @if($errors->getBag('default')->any())
                    document.getElementById('assign-admin-modal')?.showModal();
                @elseif($errors->getBag('organization')->any() && session('org_edit_error_id'))
                    document.getElementById('edit-org-{{ session('org_edit_error_id') }}')?.showModal();
                @elseif($errors->getBag('organization')->any())
                    document.getElementById('create-org-modal')?.showModal();
                @endif
            });
        </script>
    @endif
</x-layouts.app>
