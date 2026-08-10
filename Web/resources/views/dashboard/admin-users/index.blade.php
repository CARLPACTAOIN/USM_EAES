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

    @if($errors->getBag('default')->any() || $errors->getBag('organization')->any())
        <script>
            document.addEventListener('DOMContentLoaded', () => {
                @if($errors->getBag('default')->any())
                    const assignAdminModal = document.getElementById('assign-admin-modal');
                    if (assignAdminModal) {
                        if (assignAdminModal.open) assignAdminModal.close();
                        assignAdminModal.showModal();
                    }
                @elseif($errors->getBag('organization')->any() && session('org_edit_error_id'))
                    const editOrgModal = document.getElementById('edit-org-{{ session('org_edit_error_id') }}');
                    if (editOrgModal && !editOrgModal.open) editOrgModal.showModal();
                @elseif($errors->getBag('organization')->any())
                    const createOrgModal = document.getElementById('create-org-modal');
                    if (createOrgModal && !createOrgModal.open) createOrgModal.showModal();
                @endif
            });
        </script>
    @endif
</x-layouts.app>
