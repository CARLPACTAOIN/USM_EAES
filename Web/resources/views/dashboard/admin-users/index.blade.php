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

        <div class="admin-access-metrics">
            <div class="admin-access-metric">
                <span class="admin-access-metric-label">Active Assignments</span>
                <strong class="admin-access-metric-value">{{ $stats['active_assignments'] }}</strong>
                <span class="admin-access-metric-note">{{ $stats['total_assignments'] }} total records</span>
            </div>
            <div class="admin-access-metric">
                <span class="admin-access-metric-label">Active Organizations</span>
                <strong class="admin-access-metric-value">{{ $stats['active_organizations'] }}</strong>
                <span class="admin-access-metric-note">{{ $stats['total_organizations'] }} official records</span>
            </div>
            <div class="admin-access-metric">
                <span class="admin-access-metric-label">Assignable Roles</span>
                <strong class="admin-access-metric-value">{{ count($assignableRoles) }}</strong>
                <span class="admin-access-metric-note">USG, LSG, Society, Academic, Office</span>
            </div>
        </div>

        {{-- Assignment creation --}}
        @include('dashboard.admin-users.partials.assign-admin-form', [
            'assignmentOrganizations' => $assignmentOrganizations,
            'assignmentColleges' => $assignmentColleges,
            'adminRoles' => $assignableRoles,
        ])

        <div x-data="{
            activeTab: @js($activeTab),
            init() {
                @if($errors->getBag('default')->any())
                    this.activeTab = 'assignments';
                @elseif($errors->getBag('organization')->any())
                    this.activeTab = 'organizations';
                @endif
            },
            switchTab(tab) {
                this.activeTab = tab;
                const url = new URL(window.location);
                url.searchParams.set('tab', tab);
                window.history.replaceState({}, '', url);
            }
        }">
            {{-- Segmented Tab Control --}}
            <div class="flex items-center gap-2 p-1 rounded-xl bg-(--color-surface-raised) border border-(--color-border) w-fit mb-4">
                <button type="button"
                        @click="switchTab('organizations')"
                        :class="activeTab === 'organizations'
                            ? 'bg-(--color-accent) text-white shadow-sm'
                            : 'text-(--color-text-secondary) hover:text-(--color-text-primary) hover:bg-(--color-surface-raised)'"
                        class="flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium transition-all duration-150 focus:outline-none cursor-pointer">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0H5m14 0h2m-2 0h-4m-5 0H3m2 0h4M9 7h6m-6 4h6m-6 4h6"/>
                    </svg>
                    Organizations
                    <span :class="activeTab === 'organizations' ? 'bg-white/20 text-white' : 'bg-(--color-surface-inset) text-(--color-text-secondary) border border-(--color-border)'"
                          class="inline-flex items-center justify-center min-w-[20px] h-5 px-1.5 rounded-full text-[10px] font-bold transition-colors">
                        {{ $organizations->total() }}
                    </span>
                </button>
                <button type="button"
                        @click="switchTab('assignments')"
                        :class="activeTab === 'assignments'
                            ? 'bg-(--color-accent) text-white shadow-sm'
                            : 'text-(--color-text-secondary) hover:text-(--color-text-primary) hover:bg-(--color-surface-raised)'"
                        class="flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium transition-all duration-150 focus:outline-none cursor-pointer">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z"/>
                    </svg>
                    Admin Assignments
                    <span :class="activeTab === 'assignments' ? 'bg-white/20 text-white' : 'bg-(--color-surface-inset) text-(--color-text-secondary) border border-(--color-border)'"
                          class="inline-flex items-center justify-center min-w-[20px] h-5 px-1.5 rounded-full text-[10px] font-bold transition-colors">
                        {{ $assignments->total() }}
                    </span>
                </button>
            </div>

            <div class="card admin-access-table-card overflow-hidden">
                {{-- Organizations Tab Content --}}
                <div x-show="activeTab === 'organizations'" x-transition>
                    <div class="admin-access-section-header">
                        <div>
                            <h2 class="font-semibold text-(--color-text-primary)">Organizations</h2>
                            <p class="text-xs text-(--color-text-secondary) mt-0.5">Official USG, LSG, and Society records</p>
                        </div>
                        <button type="button" onclick="document.getElementById('create-org-modal')?.showModal()" class="btn btn-secondary btn-sm cursor-pointer">New Organization</button>
                    </div>

                    <form method="GET" action="{{ route('dashboard.admin-users') }}" class="p-4 border-b border-(--color-border) bg-(--color-surface-raised)">
                        <input type="hidden" name="tab" value="organizations">
                        @if(request()->filled('orgs_page'))
                            <input type="hidden" name="orgs_page" value="{{ request('orgs_page') }}">
                        @endif
                        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-3">
                            <div class="sm:col-span-2">
                                <label for="org-search" class="sr-only">Search organizations</label>
                                <input type="search"
                                       id="org-search"
                                       name="org_search"
                                       value="{{ request('org_search') }}"
                                       placeholder="Search organization name or acronym"
                                       class="form-input">
                            </div>
                            <div>
                                <label for="org-type" class="sr-only">Organization type</label>
                                <select id="org-type" name="org_type" class="form-input form-select">
                                    <option value="">All types</option>
                                    <option value="usg" @selected(request('org_type') === 'usg')>USG</option>
                                    <option value="lsg" @selected(request('org_type') === 'lsg')>LSG</option>
                                    <option value="society" @selected(request('org_type') === 'society')>Society</option>
                                </select>
                            </div>
                            <div>
                                <label for="org-status" class="sr-only">Organization status</label>
                                <select id="org-status" name="org_status" class="form-input form-select">
                                    <option value="">All statuses</option>
                                    <option value="active" @selected(request('org_status') === 'active')>Active</option>
                                    <option value="inactive" @selected(request('org_status') === 'inactive')>Inactive</option>
                                </select>
                            </div>
                        </div>
                        <div class="flex flex-wrap items-center justify-between gap-3 mt-3">
                            <p class="text-xs text-(--color-text-tertiary) font-data">
                                Showing {{ $organizations->firstItem() ?? 0 }}-{{ $organizations->lastItem() ?? 0 }} of {{ $organizations->total() }}
                            </p>
                            <div class="flex items-center gap-2">
                                @if(request()->filled('org_search') || request()->filled('org_type') || request()->filled('org_status'))
                                    <a href="{{ route('dashboard.admin-users', ['tab' => 'organizations']) }}" class="btn btn-secondary btn-sm">Clear</a>
                                @endif
                                <button type="submit" class="btn btn-primary btn-sm">Filter</button>
                            </div>
                        </div>
                    </form>

                    <div class="admin-access-record-list">
                        @forelse($organizations as $organization)
                            <article class="admin-access-record-row">
                                <div class="admin-access-record-main">
                                    <div class="admin-access-entity">
                                        <span class="admin-access-entity-mark">{{ str($organization->acronym)->substr(0, 2)->upper() }}</span>
                                        <span class="min-w-0">
                                            <span class="block font-medium text-(--color-text-primary) truncate">{{ $organization->name }}</span>
                                            <span class="block text-xs text-(--color-text-secondary) font-data truncate">{{ $organization->acronym }}</span>
                                        </span>
                                    </div>
                                    <div class="admin-access-record-meta">
                                        <span class="admin-access-type-pill">{{ strtoupper($organization->type) }}</span>
                                        <span class="admin-access-meta-chip">{{ $organization->college?->code ?? 'University-wide' }}</span>
                                        <span class="badge badge-{{ $organization->status === 'active' ? 'approved' : 'rejected' }}">{{ ucfirst($organization->status) }}</span>
                                    </div>
                                </div>
                                <div class="admin-access-record-detail">
                                    @if($organization->type === 'society')
                                        <span class="admin-access-detail-label">Programs</span>
                                        <span class="admin-access-muted-line">{{ $organization->programs->pluck('code')->take(5)->join(', ') ?: 'None linked' }}</span>
                                    @else
                                        <span class="admin-access-detail-label">Scope</span>
                                        <span>{{ $organization->college?->name ?? 'University-wide governance' }}</span>
                                    @endif
                                </div>
                                <div class="admin-access-record-actions">
                                    <button type="button"
                                            onclick="document.getElementById('edit-org-{{ $organization->id }}')?.showModal()"
                                            class="btn btn-secondary btn-sm">Edit</button>
                                </div>
                            </article>
                        @empty
                            <div class="empty-state py-8">
                                <p class="empty-state-title">No organizations yet</p>
                                <p class="empty-state-description">Create the first official organization using the New Organization action.</p>
                            </div>
                        @endforelse
                    </div>
                    @if($organizations->hasPages())
                        <div class="card-footer">{{ $organizations->links() }}</div>
                    @endif
                </div>

                {{-- Admin Assignments Tab Content --}}
                <div x-show="activeTab === 'assignments'" x-cloak x-transition>
                    <div class="admin-access-section-header">
                        <div>
                            <h2 class="font-semibold text-(--color-text-primary)">Admin Assignments</h2>
                            <p class="text-xs text-(--color-text-secondary) mt-0.5">Current and historical admin authority</p>
                        </div>
                        <button type="button" onclick="document.getElementById('assign-admin-modal')?.showModal()" class="btn btn-secondary btn-sm cursor-pointer">Assign Admin</button>
                    </div>

                    <form method="GET" action="{{ route('dashboard.admin-users') }}" class="p-4 border-b border-(--color-border) bg-(--color-surface-raised)">
                        <input type="hidden" name="tab" value="assignments">
                        @if(request()->filled('page'))
                            <input type="hidden" name="page" value="{{ request('page') }}">
                        @endif
                        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-3">
                            <div class="sm:col-span-2">
                                <label for="assign-search" class="sr-only">Search admin users</label>
                                <input type="search"
                                       id="assign-search"
                                       name="assign_search"
                                       value="{{ request('assign_search') }}"
                                       placeholder="Search admin name, email, or scope"
                                       class="form-input">
                            </div>
                            <div>
                                <label for="assign-role" class="sr-only">Role</label>
                                <select id="assign-role" name="assign_role" class="form-input form-select">
                                    <option value="">All roles</option>
                                    @foreach($adminRoles as $roleOption)
                                        <option value="{{ $roleOption }}" @selected(request('assign_role') === $roleOption)>{{ $roleOption }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label for="assign-status" class="sr-only">Status</label>
                                <select id="assign-status" name="assign_status" class="form-input form-select">
                                    <option value="">All statuses</option>
                                    <option value="active" @selected(request('assign_status') === 'active')>Active</option>
                                    <option value="expired" @selected(request('assign_status') === 'expired')>Expired</option>
                                    <option value="revoked" @selected(request('assign_status') === 'revoked')>Revoked</option>
                                </select>
                            </div>
                            <div>
                                <label for="assign-term" class="sr-only">Term</label>
                                <select id="assign-term" name="assign_term" class="form-input form-select">
                                    <option value="">All terms</option>
                                    <option value="current" @selected(request('assign_term') === 'current')>Current term</option>
                                    <option value="previous" @selected(request('assign_term') === 'previous')>Previous terms</option>
                                </select>
                            </div>
                        </div>
                        <div class="flex flex-wrap items-center justify-between gap-3 mt-3">
                            <p class="text-xs text-(--color-text-tertiary) font-data">
                                Showing {{ $assignments->firstItem() ?? 0 }}-{{ $assignments->lastItem() ?? 0 }} of {{ $assignments->total() }}
                            </p>
                            <div class="flex items-center gap-2">
                                @if(request()->filled('assign_search') || request()->filled('assign_role') || request()->filled('assign_status') || request()->filled('assign_term'))
                                    <a href="{{ route('dashboard.admin-users', ['tab' => 'assignments']) }}" class="btn btn-secondary btn-sm">Clear</a>
                                @endif
                                <button type="submit" class="btn btn-primary btn-sm">Filter</button>
                            </div>
                        </div>
                    </form>

                    <div class="admin-access-record-list">
                        @forelse($assignments as $assignment)
                            <article class="admin-access-record-row admin-access-assignment-row">
                                <div class="admin-access-record-main">
                                    <div class="admin-access-entity">
                                        <span class="admin-access-entity-mark">{{ str($assignment->user->name)->substr(0, 1)->upper() }}</span>
                                        <span class="min-w-0">
                                            <span class="block font-medium text-(--color-text-primary) truncate">{{ $assignment->user->name }}</span>
                                            <span class="block text-xs text-(--color-text-secondary) font-data truncate">{{ $assignment->user->email }}</span>
                                        </span>
                                    </div>
                                    <div class="admin-access-record-meta">
                                        <span class="badge badge-approved">{{ $assignment->role_name }}</span>
                                        @if($assignment->is_primary_admin)
                                            <span class="badge badge-pending">Primary</span>
                                        @endif
                                        <span class="badge badge-{{ $assignment->status === 'active' ? 'approved' : ($assignment->status === 'revoked' ? 'rejected' : 'pending') }}">{{ ucfirst($assignment->status) }}</span>
                                    </div>
                                </div>
                                <div class="admin-access-record-detail">
                                    <span class="admin-access-detail-label">Scope</span>
                                    <span>{{ $assignment->organization?->acronym ?? $assignment->college?->code ?? 'University-wide' }}</span>
                                    <span class="admin-access-detail-separator"></span>
                                    <span class="admin-access-detail-label">Term</span>
                                    <span class="font-data">{{ $assignment->academic_year }}</span>
                                    @if($assignment->term_start || $assignment->term_end)
                                        <span class="text-xs text-(--color-text-tertiary)">{{ $assignment->term_start?->format('M d, Y') ?? 'Open' }} - {{ $assignment->term_end?->format('M d, Y') ?? 'Open' }}</span>
                                    @endif
                                </div>
                                <div class="admin-access-record-actions">
                                    @if($assignment->status === 'active')
                                        <div class="admin-access-action-row">
                                            <form method="POST" action="{{ route('dashboard.admin-assignments.expire', $assignment) }}">
                                                @csrf
                                                <button type="submit" class="btn btn-secondary btn-sm">Expire</button>
                                            </form>
                                            <button type="button"
                                                    onclick="document.getElementById('revoke-assignment-{{ $assignment->id }}')?.showModal()"
                                                    class="btn btn-destructive btn-sm">Revoke</button>
                                        </div>
                                    @else
                                        <span class="text-xs text-(--color-text-secondary)">Closed</span>
                                    @endif
                                </div>
                            </article>
                        @empty
                            <div class="empty-state py-8">
                                <p class="empty-state-title">No assignments</p>
                            </div>
                        @endforelse
                    </div>
                    @if($assignments->hasPages())
                        <div class="card-footer">{{ $assignments->links() }}</div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    @foreach($assignments as $assignment)
        @if($assignment->status === \App\Models\AdminAssignment::STATUS_ACTIVE)
            <dialog id="revoke-assignment-{{ $assignment->id }}"
                    class="confirm-modal"
                    aria-labelledby="revoke-assignment-{{ $assignment->id }}-title"
                    onclick="if (event.target === this) this.close()">
                <div class="confirm-modal-panel" onclick="event.stopPropagation()">
                    <div class="confirm-modal-header">
                        <span class="confirm-modal-icon" aria-hidden="true">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 9v3.75m0 3.75h.008v.008H12v-.008zM10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
                            </svg>
                        </span>
                        <div>
                            <h2 id="revoke-assignment-{{ $assignment->id }}-title" class="confirm-modal-title">Revoke admin assignment?</h2>
                            <p class="confirm-modal-description">{{ $assignment->user->name }} will lose {{ $assignment->role_name }} access. Student profile membership will not change.</p>
                        </div>
                    </div>
                    <form method="POST" action="{{ route('dashboard.admin-assignments.revoke', $assignment) }}" class="space-y-4">
                        @csrf
                        <div>
                            <label class="form-label">Reason</label>
                            <input type="text" name="reason" class="form-input" value="Revoked by OSA" maxlength="1000">
                        </div>
                        <div class="confirm-modal-actions">
                            <button type="button" onclick="document.getElementById('revoke-assignment-{{ $assignment->id }}')?.close()" class="btn btn-secondary">Cancel</button>
                            <button type="submit" class="btn btn-destructive">Revoke Assignment</button>
                        </div>
                    </form>
                </div>
            </dialog>
        @endif
    @endforeach

    {{-- Create organization modal --}}
    <dialog id="create-org-modal"
            class="proposal-modal modal-sm"
            aria-labelledby="create-org-modal-title"
            onclick="if (event.target === this) this.close()"
            x-data="organizationForm({
                colleges: @js($collegePrograms),
                type: @js(old('type', 'society')),
                collegeId: @js(old('college_id', '')),
            })">
        <form method="POST"
              action="{{ route('dashboard.organizations.store') }}"
              enctype="multipart/form-data"
              class="proposal-modal-form"
              onclick="event.stopPropagation()">
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

    @foreach($organizations as $organization)
        <dialog id="edit-org-{{ $organization->id }}"
                class="proposal-modal modal-sm"
                aria-labelledby="edit-org-{{ $organization->id }}-title"
                onclick="if (event.target === this) this.close()"
                x-data="organizationForm({
                    colleges: @js($collegePrograms),
                    type: @js($organization->type),
                    collegeId: @js($organization->college_id),
                    programIds: @js($organization->programs->pluck('id')->values()),
                })">
            <form method="POST"
                  action="{{ route('dashboard.organizations.update', $organization) }}"
                  enctype="multipart/form-data"
                  class="proposal-modal-form"
                  onclick="event.stopPropagation()">
                @csrf
                @method('PUT')
                <div class="proposal-modal-header">
                    <div class="proposal-modal-title-row">
                        <div>
                            <p class="proposal-modal-kicker">Edit organization</p>
                            <h2 id="edit-org-{{ $organization->id }}-title" class="proposal-modal-title">{{ $organization->acronym }}</h2>
                        </div>
                    </div>
                    <button type="button" onclick="document.getElementById('edit-org-{{ $organization->id }}').close()" class="btn-icon btn-ghost" aria-label="Close">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>
                <div class="proposal-modal-body space-y-4">
                    <input type="hidden" name="type" value="{{ $organization->type }}">
                    @if($organization->college_id)
                        <input type="hidden" name="college_id" value="{{ $organization->college_id }}">
                    @endif
                    <div>
                        <label class="form-label">Name</label>
                        <input type="text" name="name" value="{{ session('org_edit_error_id') === $organization->id ? old('name', $organization->name) : $organization->name }}" class="form-input @error('name', 'organization') form-input-error @enderror" required>
                        @if(session('org_edit_error_id') === $organization->id)
                            @error('name', 'organization') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                        @endif
                    </div>
                    <div>
                        <label class="form-label">Acronym</label>
                        <input type="text" name="acronym" value="{{ session('org_edit_error_id') === $organization->id ? old('acronym', $organization->acronym) : $organization->acronym }}" class="form-input @error('acronym', 'organization') form-input-error @enderror" required>
                        @if(session('org_edit_error_id') === $organization->id)
                            @error('acronym', 'organization') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                        @endif
                    </div>
                    <div>
                        <label class="form-label">Status</label>
                        <select name="status" class="form-input form-select @error('status', 'organization') form-input-error @enderror" required>
                            <option value="active" @selected((session('org_edit_error_id') === $organization->id ? old('status', $organization->status) : $organization->status) === 'active')>Active</option>
                            <option value="inactive" @selected((session('org_edit_error_id') === $organization->id ? old('status', $organization->status) : $organization->status) === 'inactive')>Inactive</option>
                        </select>
                        @if(session('org_edit_error_id') === $organization->id)
                            @error('status', 'organization') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                        @endif
                    </div>
                    @if(in_array($organization->type, ['society', 'non_academic_org'], true))
                        <div>
                            <label class="form-label">Covered Programs</label>
                            <select name="program_ids[]" multiple class="form-input min-h-28 @error('program_ids', 'organization') form-input-error @enderror">
                                @foreach($organization->college?->programs ?? [] as $program)
                                    <option value="{{ $program->id }}" @selected(session('org_edit_error_id') === $organization->id ? in_array($program->id, old('program_ids', []), true) : $organization->programs->contains('id', $program->id))>{{ $program->code }} — {{ $program->name }}</option>
                                @endforeach
                            </select>
                            @if(session('org_edit_error_id') === $organization->id)
                                @error('program_ids', 'organization') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                            @endif
                        </div>
                    @endif
                    <div>
                        <label class="form-label">Logo <span class="text-(--color-text-tertiary)">(optional)</span></label>
                        <input type="file" name="logo" accept="image/*" class="form-input @error('logo', 'organization') form-input-error @enderror">
                        @if(session('org_edit_error_id') === $organization->id)
                            @error('logo', 'organization') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                        @endif
                    </div>
                </div>
                <div class="proposal-modal-footer">
                    <div class="proposal-footer-actions">
                        <button type="button" onclick="document.getElementById('edit-org-{{ $organization->id }}').close()" class="btn btn-secondary">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                    </div>
                </div>
            </form>
        </dialog>
    @endforeach

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
                programIds: config.programIds || [],
                programsForCollege() {
                    const college = this.colleges.find((item) => item.id === this.collegeId);
                    return college?.programs || [];
                },
                typeUsesCollege() {
                    return ['society', 'lsg', 'academic_unit'].includes(this.type);
                },
                typeUsesPrograms() {
                    return ['society', 'non_academic_org'].includes(this.type);
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
