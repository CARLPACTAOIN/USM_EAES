<div>
    @if($notice)
        <div class="mb-4 rounded-lg border px-4 py-3 text-sm font-medium" style="background-color: var(--color-success-light); border-color: var(--color-success); color: var(--color-success);" role="status">
            {{ $notice }}
        </div>
    @endif

    @if($errorNotice)
        <div class="mb-4 rounded-lg border px-4 py-3 text-sm font-medium" style="background-color: var(--color-destructive-light); border-color: var(--color-destructive); color: var(--color-destructive);" role="alert">
            {{ $errorNotice }}
        </div>
    @endif

    <div class="admin-access-tabs flex flex-wrap items-center gap-2 p-1 rounded-xl bg-(--color-surface-raised) border border-(--color-border) w-fit max-w-full mb-4">
        <button type="button"
                wire:click="setTab('organizations')"
                wire:loading.attr="disabled"
                class="flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium transition-all duration-150 focus:outline-none cursor-pointer {{ $activeTab === 'organizations' ? 'bg-(--color-accent) text-white shadow-sm' : 'text-(--color-text-secondary) hover:text-(--color-text-primary) hover:bg-(--color-surface-raised)' }}">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0H5m14 0h2m-2 0h-4m-5 0H3m2 0h4M9 7h6m-6 4h6m-6 4h6"/>
            </svg>
            Organizations
            <span class="inline-flex items-center justify-center min-w-[20px] h-5 px-1.5 rounded-full text-[10px] font-bold transition-colors {{ $activeTab === 'organizations' ? 'bg-white/20 text-white' : 'bg-(--color-surface-inset) text-(--color-text-secondary) border border-(--color-border)' }}">
                {{ $organizations->total() }}
            </span>
        </button>
        <button type="button"
                wire:click="setTab('assignments')"
                wire:loading.attr="disabled"
                class="flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium transition-all duration-150 focus:outline-none cursor-pointer {{ $activeTab === 'assignments' ? 'bg-(--color-accent) text-white shadow-sm' : 'text-(--color-text-secondary) hover:text-(--color-text-primary) hover:bg-(--color-surface-raised)' }}">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z"/>
            </svg>
            Admin Assignments
            <span class="inline-flex items-center justify-center min-w-[20px] h-5 px-1.5 rounded-full text-[10px] font-bold transition-colors {{ $activeTab === 'assignments' ? 'bg-white/20 text-white' : 'bg-(--color-surface-inset) text-(--color-text-secondary) border border-(--color-border)' }}">
                {{ $assignments->total() }}
            </span>
        </button>
        <button type="button"
                wire:click="setTab('audit')"
                wire:loading.attr="disabled"
                class="flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium transition-all duration-150 focus:outline-none cursor-pointer {{ $activeTab === 'audit' ? 'bg-(--color-accent) text-white shadow-sm' : 'text-(--color-text-secondary) hover:text-(--color-text-primary) hover:bg-(--color-surface-raised)' }}">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 6v6l4 2m6-2a10 10 0 11-20 0 10 10 0 0120 0z"/>
            </svg>
            Audit History
            <span class="inline-flex items-center justify-center min-w-[20px] h-5 px-1.5 rounded-full text-[10px] font-bold transition-colors {{ $activeTab === 'audit' ? 'bg-white/20 text-white' : 'bg-(--color-surface-inset) text-(--color-text-secondary) border border-(--color-border)' }}">
                {{ $auditLogs->total() }}
            </span>
        </button>
    </div>

    <div class="card admin-access-table-card overflow-hidden relative">
        <div wire:loading.delay.class="opacity-60" wire:target="setTab,clearOrgFilters,clearAssignmentFilters,clearAuditFilters,expireAssignment,revokeAssignment,orgSearch,orgType,orgStatus,assignSearch,assignRole,assignStatus,assignTerm,auditSearch,auditAction,auditFrom,auditTo">
            @if($activeTab === 'organizations')
                <div>
                    <div class="admin-access-section-header">
                        <div>
                            <h2 class="font-semibold text-(--color-text-primary)">Organizations</h2>
                            <p class="text-xs text-(--color-text-secondary) mt-0.5">Official USG, LSG, Society, academic unit, and office records</p>
                        </div>
                        <button type="button" onclick="document.getElementById('create-org-modal')?.showModal()" class="btn btn-secondary btn-sm cursor-pointer">New Organization</button>
                    </div>

                    <form wire:submit.prevent="$refresh" class="p-4 border-b border-(--color-border) bg-(--color-surface-raised)">
                        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-3">
                            <div class="sm:col-span-2">
                                <label for="org-search" class="sr-only">Search organizations</label>
                                <input type="search"
                                       id="org-search"
                                       wire:model.live.debounce.400ms="orgSearch"
                                       placeholder="Search organization name or acronym"
                                       class="form-input">
                            </div>
                            <div>
                                <label for="org-type" class="sr-only">Organization type</label>
                                <select id="org-type" wire:model.live="orgType" class="form-input form-select">
                                    <option value="">All types</option>
                                    <option value="usg">USG</option>
                                    <option value="lsg">LSG</option>
                                    <option value="society">Society</option>
                                    <option value="non_academic_org">Non-academic Organization</option>
                                    <option value="academic_unit">Academic Unit</option>
                                    <option value="administrative_office">Administrative Office</option>
                                </select>
                            </div>
                            <div>
                                <label for="org-status" class="sr-only">Organization status</label>
                                <select id="org-status" wire:model.live="orgStatus" class="form-input form-select">
                                    <option value="">All statuses</option>
                                    <option value="active">Active</option>
                                    <option value="inactive">Inactive</option>
                                </select>
                            </div>
                        </div>
                        <div class="flex flex-wrap items-center justify-between gap-3 mt-3">
                            <p class="text-xs text-(--color-text-tertiary) font-data">
                                Showing {{ $organizations->firstItem() ?? 0 }}-{{ $organizations->lastItem() ?? 0 }} of {{ $organizations->total() }}
                            </p>
                            <div class="flex items-center gap-2">
                                @if($orgSearch !== '' || $orgType !== '' || $orgStatus !== '')
                                    <button type="button" wire:click="clearOrgFilters" class="btn btn-secondary btn-sm">Clear</button>
                                @endif
                                <button type="submit" class="btn btn-primary btn-sm" wire:loading.attr="disabled">Filter</button>
                            </div>
                        </div>
                    </form>

                    <div class="admin-access-record-list">
                        @forelse($organizations as $organization)
                            <article class="admin-access-record-row" wire:key="org-row-{{ $organization->id }}">
                                <div class="admin-access-record-main">
                                    <div class="admin-access-entity">
                                        <x-organization-mark :organization="$organization" class="admin-access-entity-mark" />
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
                                    @if(in_array($organization->type, ['society', 'non_academic_org'], true))
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
                                <p class="empty-state-title">No organizations found</p>
                                <p class="empty-state-description">Adjust the filters or create a new official organization.</p>
                            </div>
                        @endforelse
                    </div>

                    @if($organizations->hasPages())
                        <div class="card-footer">{{ $organizations->links() }}</div>
                    @endif

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
                                })"
                                wire:key="org-modal-{{ $organization->id }}">
                            <form method="POST"
                                  action="{{ route('dashboard.organizations.update', $organization) }}"
                                  enctype="multipart/form-data"
                                  class="proposal-modal-form">
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
                                                    <option value="{{ $program->id }}" @selected(session('org_edit_error_id') === $organization->id ? in_array($program->id, old('program_ids', []), true) : $organization->programs->contains('id', $program->id))>{{ $program->code }} - {{ $program->name }}</option>
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
                </div>
            @elseif($activeTab === 'assignments')
                <div>
                    <div class="admin-access-section-header">
                        <div>
                            <h2 class="font-semibold text-(--color-text-primary)">Admin Assignments</h2>
                            <p class="text-xs text-(--color-text-secondary) mt-0.5">Current and historical admin authority</p>
                        </div>
                        <button type="button" onclick="document.getElementById('assign-admin-modal')?.showModal()" class="btn btn-secondary btn-sm cursor-pointer">Assign Admin</button>
                    </div>

                    <form wire:submit.prevent="$refresh" class="p-4 border-b border-(--color-border) bg-(--color-surface-raised)">
                        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-3">
                            <div class="sm:col-span-2">
                                <label for="assign-search" class="sr-only">Search admin users</label>
                                <input type="search"
                                       id="assign-search"
                                       wire:model.live.debounce.400ms="assignSearch"
                                       placeholder="Search admin name, email, or scope"
                                       class="form-input">
                            </div>
                            <div>
                                <label for="assign-role" class="sr-only">Role</label>
                                <select id="assign-role" wire:model.live="assignRole" class="form-input form-select">
                                    <option value="">All roles</option>
                                    @foreach($adminRoles as $roleOption)
                                        <option value="{{ $roleOption }}">{{ $roleOption }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label for="assign-status" class="sr-only">Status</label>
                                <select id="assign-status" wire:model.live="assignStatus" class="form-input form-select">
                                    <option value="">All statuses</option>
                                    <option value="active">Active</option>
                                    <option value="expired">Expired</option>
                                    <option value="revoked">Revoked</option>
                                </select>
                            </div>
                            <div>
                                <label for="assign-term" class="sr-only">Term</label>
                                <select id="assign-term" wire:model.live="assignTerm" class="form-input form-select">
                                    <option value="">All terms</option>
                                    <option value="current">Current term</option>
                                    <option value="previous">Previous terms</option>
                                </select>
                            </div>
                        </div>
                        <div class="flex flex-wrap items-center justify-between gap-3 mt-3">
                            <p class="text-xs text-(--color-text-tertiary) font-data">
                                Showing {{ $assignments->firstItem() ?? 0 }}-{{ $assignments->lastItem() ?? 0 }} of {{ $assignments->total() }}
                            </p>
                            <div class="flex items-center gap-2">
                                @if($assignSearch !== '' || $assignRole !== '' || $assignStatus !== '' || $assignTerm !== '')
                                    <button type="button" wire:click="clearAssignmentFilters" class="btn btn-secondary btn-sm">Clear</button>
                                @endif
                                <button type="submit" class="btn btn-primary btn-sm" wire:loading.attr="disabled">Filter</button>
                            </div>
                        </div>
                    </form>

                    <div class="admin-access-record-list">
                        @forelse($assignments as $assignment)
                            <article class="admin-access-record-row admin-access-assignment-row" wire:key="assignment-row-{{ $assignment->id }}">
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
                                    @if($assignment->status === \App\Models\AdminAssignment::STATUS_ACTIVE)
                                        <div class="admin-access-action-row">
                                            <button type="button"
                                                    wire:click="expireAssignment('{{ $assignment->id }}')"
                                                    wire:loading.attr="disabled"
                                                    wire:target="expireAssignment"
                                                    class="btn btn-secondary btn-sm">Expire</button>
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
                                <p class="empty-state-title">No assignments found</p>
                            </div>
                        @endforelse
                    </div>

                    @if($assignments->hasPages())
                        <div class="card-footer">{{ $assignments->links() }}</div>
                    @endif

                    @foreach($assignments as $assignment)
                        @if($assignment->status === \App\Models\AdminAssignment::STATUS_ACTIVE)
                            <dialog id="revoke-assignment-{{ $assignment->id }}"
                                    class="confirm-modal"
                                    aria-labelledby="revoke-assignment-{{ $assignment->id }}-title"
                                    onclick="if (event.target === this) this.close()"
                                    x-on:close-admin-revoke-modal.window="if ($event.detail.id === '{{ $assignment->id }}') $el.close()"
                                    wire:key="assignment-revoke-modal-{{ $assignment->id }}">
                                <div class="confirm-modal-panel">
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
                                    <form wire:submit.prevent="revokeAssignment('{{ $assignment->id }}')" class="space-y-4">
                                        <div>
                                            <label class="form-label">Reason</label>
                                            <input type="text"
                                                   wire:model="revokeReasons.{{ $assignment->id }}"
                                                   class="form-input @error('revokeReasons.' . $assignment->id) form-input-error @enderror"
                                                   placeholder="Revoked by OSA"
                                                   maxlength="1000">
                                            @error('revokeReasons.' . $assignment->id) <p class="form-error" role="alert">{{ $message }}</p> @enderror
                                        </div>
                                        <div class="confirm-modal-actions">
                                            <button type="button" onclick="document.getElementById('revoke-assignment-{{ $assignment->id }}')?.close()" class="btn btn-secondary">Cancel</button>
                                            <button type="submit" wire:loading.attr="disabled" wire:target="revokeAssignment" class="btn btn-destructive">
                                                <span wire:loading.remove wire:target="revokeAssignment">Revoke Assignment</span>
                                                <span wire:loading wire:target="revokeAssignment">Revoking...</span>
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </dialog>
                        @endif
                    @endforeach
                </div>
            @else
                <div>
                    <div class="admin-access-section-header">
                        <div>
                            <h2 class="font-semibold text-(--color-text-primary)">Audit History</h2>
                            <p class="text-xs text-(--color-text-secondary) mt-0.5">Immutable Admin Access governance actions</p>
                        </div>
                    </div>

                    <form wire:submit.prevent="$refresh" class="p-4 border-b border-(--color-border) bg-(--color-surface-raised)">
                        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-5 gap-3">
                            <div class="sm:col-span-2">
                                <label for="audit-search" class="sr-only">Search audit history</label>
                                <input type="search"
                                       id="audit-search"
                                       wire:model.live.debounce.400ms="auditSearch"
                                       placeholder="Search actor, action, role, scope, or reason"
                                       class="form-input">
                            </div>
                            <div>
                                <label for="audit-action" class="sr-only">Audit action</label>
                                <select id="audit-action" wire:model.live="auditAction" class="form-input form-select">
                                    <option value="">All actions</option>
                                    @foreach($auditActions as $action)
                                        <option value="{{ $action }}">{{ $auditActionLabels[$action] ?? str($action)->headline() }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label for="audit-from" class="sr-only">From date</label>
                                <input type="date" id="audit-from" wire:model.live="auditFrom" class="form-input">
                            </div>
                            <div>
                                <label for="audit-to" class="sr-only">To date</label>
                                <input type="date" id="audit-to" wire:model.live="auditTo" class="form-input">
                            </div>
                        </div>
                        <div class="flex flex-wrap items-center justify-between gap-3 mt-3">
                            <p class="text-xs text-(--color-text-tertiary) font-data">
                                Showing {{ $auditLogs->firstItem() ?? 0 }}-{{ $auditLogs->lastItem() ?? 0 }} of {{ $auditLogs->total() }}
                            </p>
                            <div class="flex items-center gap-2">
                                @if($auditSearch !== '' || $auditAction !== '' || $auditFrom !== '' || $auditTo !== '')
                                    <button type="button" wire:click="clearAuditFilters" class="btn btn-secondary btn-sm">Clear</button>
                                @endif
                                <button type="submit" class="btn btn-primary btn-sm" wire:loading.attr="disabled">Filter</button>
                            </div>
                        </div>
                    </form>

                    <div class="admin-access-record-list">
                        @forelse($auditLogs as $auditLog)
                            @php
                                $details = $auditLog->details ?? [];
                                $label = $auditActionLabels[$auditLog->action] ?? str($auditLog->action)->headline();
                                $affectedUser = !empty($details['user_id']) ? $auditUsers->get($details['user_id']) : null;
                                $organization = !empty($details['organization_id']) ? $auditOrganizations->get($details['organization_id']) : null;
                                $college = !empty($details['college_id']) ? $auditColleges->get($details['college_id']) : null;
                                $organizationName = $organization?->acronym ?? $details['acronym'] ?? $details['name'] ?? null;
                                $scopeLabel = $organizationName ?? $college?->code ?? (!empty($details['college_id']) ? str($details['college_id'])->substr(0, 8) : 'University-wide');
                                $initials = str($label)->explode(' ')->filter()->map(fn ($part) => str($part)->substr(0, 1))->take(2)->join('');
                            @endphp
                            <article class="admin-access-record-row admin-access-audit-row" wire:key="audit-row-{{ $auditLog->id }}">
                                <div class="admin-access-record-main">
                                    <div class="admin-access-entity">
                                        <span class="admin-access-entity-mark">{{ $initials }}</span>
                                        <span class="min-w-0">
                                            <span class="block font-medium text-(--color-text-primary) truncate">{{ $label }}</span>
                                            <span class="block text-xs text-(--color-text-secondary) font-data truncate">
                                                {{ $auditLog->created_at?->format('M d, Y g:i A') ?? 'Timestamp unavailable' }}
                                            </span>
                                        </span>
                                    </div>
                                    <div class="admin-access-record-meta">
                                        <span class="admin-access-type-pill">{{ str($auditLog->action)->replace('-', ' ')->upper() }}</span>
                                        <span class="admin-access-meta-chip">{{ $auditLog->admin?->name ?? 'Unknown actor' }}</span>
                                    </div>
                                </div>
                                <div class="admin-access-record-detail admin-access-audit-detail">
                                    @if($affectedUser)
                                        <span class="admin-access-detail-label">Account</span>
                                        <span>{{ $affectedUser->name }}</span>
                                        <span class="text-xs text-(--color-text-tertiary) font-data">{{ $affectedUser->email }}</span>
                                    @elseif(!empty($details['user_id']))
                                        <span class="admin-access-detail-label">Account</span>
                                        <span class="font-data">{{ str($details['user_id'])->substr(0, 8) }}</span>
                                    @endif

                                    @if(!empty($details['role_name']))
                                        <span class="admin-access-detail-label">Role</span>
                                        <span>{{ $details['role_name'] }}</span>
                                    @endif

                                    @if($organizationName || $college || !empty($details['college_id']))
                                        <span class="admin-access-detail-label">Scope</span>
                                        <span>{{ $scopeLabel }}</span>
                                    @endif

                                    @if(!empty($details['academic_year']))
                                        <span class="admin-access-detail-label">AY</span>
                                        <span class="font-data">{{ $details['academic_year'] }}</span>
                                    @endif

                                    @if(array_key_exists('status', $details))
                                        <span class="admin-access-detail-label">Status</span>
                                        <span>{{ ucfirst((string) $details['status']) }}</span>
                                    @endif

                                    @if(!empty($details['reason']))
                                        <span class="admin-access-detail-label">Reason</span>
                                        <span>{{ $details['reason'] }}</span>
                                    @endif

                                    @if(empty($details))
                                        <span class="text-(--color-text-tertiary)">No structured details were stored for this older audit record.</span>
                                    @endif
                                </div>
                                <div class="admin-access-record-actions">
                                    <span class="text-xs text-(--color-text-tertiary) font-data">{{ str($auditLog->target_id)->substr(0, 8) }}</span>
                                </div>
                            </article>
                        @empty
                            <div class="empty-state py-8">
                                <p class="empty-state-title">No audit history found</p>
                                <p class="empty-state-description">Admin Access actions will appear here after OSA creates, updates, revokes, or expires governance records.</p>
                            </div>
                        @endforelse
                    </div>

                    @if($auditLogs->hasPages())
                        <div class="card-footer">{{ $auditLogs->links() }}</div>
                    @endif
                </div>
            @endif
        </div>
    </div>
</div>
