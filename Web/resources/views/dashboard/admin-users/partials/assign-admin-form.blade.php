@php
    $roleMeta = [
        'USG Admin' => [
            'short' => 'USG',
            'desc' => 'University Student Government organization scope.',
            'icon' => 'M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198l.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0112 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 016 18.719m12 0a5.971 5.971 0 00-.941-3.197m0 0A5.995 5.995 0 0012 12.75a5.995 5.995 0 00-5.058 2.772m0 0a3 3 0 00-4.681 2.72 8.986 8.986 0 003.74.477m.94-3.197a5.971 5.971 0 00-.94 3.197M15 6.75a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0zm-13.5 0a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z',
        ],
        'LSG Admin' => [
            'short' => 'LSG',
            'desc' => 'College Local Student Government scope.',
            'icon' => 'M12 21v-8.25M15.75 21v-8.25M8.25 21v-8.25M3 9l9-6 9 6M4.5 10.5V21h15V10.5',
        ],
        'Society Admin' => [
            'short' => 'Society',
            'desc' => 'Official student or non-academic organization scope.',
            'icon' => 'M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21M3 3h12m-.75 4.5H21m-3.75 3.75h.008v.008h-.008v-.008zm0 3h.008v.008h-.008v-.008zm0 3h.008v.008h-.008v-.008z',
        ],
        'Academic Unit Admin' => [
            'short' => 'Academic',
            'desc' => 'College academic unit PPA and scanner scope.',
            'icon' => 'M12 21v-8.25M15.75 21v-8.25M8.25 21v-8.25M3 9l9-6 9 6M4.5 10.5V21h15V10.5',
        ],
        'Office Event Admin' => [
            'short' => 'Office',
            'desc' => 'Administrative office event organizer scope.',
            'icon' => 'M3.75 21h16.5M4.5 3h15l-.75 18H5.25L4.5 3zm4.5 4.5h6m-6 3h6m-6 3h6',
        ],
    ];
@endphp

<dialog id="assign-admin-modal"
        class="proposal-modal"
        aria-labelledby="assign-admin-modal-title"
        onclick="if (event.target === this) this.close()"
        x-data="adminAssignmentForm({
            searchUrl: @js(route('dashboard.admin-users.search')),
            organizations: @js($assignmentOrganizations),
            colleges: @js($assignmentColleges),
            oldRole: @js(old('role', '')),
            oldCollegeId: @js(old('college_id', '')),
            oldOrganizationId: @js(old('organization_id', '')),
            oldEmail: @js(old('email', '')),
            oldStudentId: @js(old('student_id_number', '')),
            oldName: @js(old('name', '')),
        })">
    <form method="POST"
          action="{{ route('dashboard.admin-users.create') }}"
          class="proposal-modal-form"
          onclick="event.stopPropagation()">
        @csrf

        <div class="proposal-modal-header">
            <div class="proposal-modal-title-row">
                <span class="proposal-modal-icon" aria-hidden="true">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/>
                    </svg>
                </span>
                <div>
                    <p class="proposal-modal-kicker">OSA assignment</p>
                    <h2 id="assign-admin-modal-title" class="proposal-modal-title">Assign Admin Authority</h2>
                    <p class="proposal-modal-subtitle">Select a person, role, scope, and term. Student profile membership is never changed.</p>
                </div>
            </div>
            <button type="button" onclick="document.getElementById('assign-admin-modal')?.close()" class="btn-icon btn-ghost" aria-label="Close assignment modal">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

        <div class="proposal-modal-body">

        {{-- 1. Person --}}
        <div class="admin-access-zone">
            <div class="admin-access-zone-head">
                <span class="admin-access-zone-step">1</span>
                <span class="admin-access-zone-title">Person</span>
            </div>

            <div class="admin-access-search-wrap" x-show="!userLocked && accountMode === 'search'" x-cloak>
                <label for="user-search" class="sr-only">Search by email or student ID</label>
                <span class="admin-access-search-icon">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                    </svg>
                </span>
                <input type="text"
                       id="user-search"
                       x-model="searchQuery"
                       @input.debounce.300ms="searchUsers()"
                       @focus="showResults = results.length > 0"
                       placeholder="Search by @usm.edu.ph email or student ID…"
                       class="form-input admin-access-search-input w-full"
                       autocomplete="off">
                <div x-show="showResults && results.length > 0"
                     @click.outside="showResults = false"
                     x-cloak
                     class="absolute z-20 mt-1 w-full rounded-xl border border-(--color-border) bg-(--color-surface) shadow-lg overflow-hidden">
                    <template x-for="user in results" :key="user.id">
                        <button type="button"
                                @click="selectUser(user)"
                                class="w-full text-left px-4 py-3 hover:bg-(--color-surface-raised) transition-colors border-b border-(--color-border) last:border-0 cursor-pointer">
                            <p class="font-medium text-sm text-(--color-text-primary)" x-text="user.name"></p>
                            <p class="text-xs text-(--color-text-secondary) font-data mt-0.5">
                                <span x-text="user.email"></span>
                                <template x-if="user.student_id_number">
                                    <span> · <span x-text="user.student_id_number"></span></span>
                                </template>
                            </p>
                        </button>
                    </template>
                </div>
            </div>

            <div class="admin-access-user-chip" x-show="userLocked" x-cloak>
                <div class="admin-access-user-avatar" x-text="userInitial()"></div>
                <div class="min-w-0 flex-1">
                    <p class="font-medium text-sm text-(--color-text-primary) truncate" x-text="name || 'Selected user'"></p>
                    <p class="text-xs text-(--color-text-secondary) font-data truncate">
                        <span x-text="email"></span>
                        <template x-if="studentId">
                            <span> · <span x-text="studentId"></span></span>
                        </template>
                    </p>
                </div>
                <button type="button" @click="clearUser()" class="btn btn-ghost btn-sm shrink-0 cursor-pointer">Change</button>
                <input type="hidden" name="email" x-model="email">
                <input type="hidden" name="student_id_number" x-model="studentId">
                <input type="hidden" name="name" x-model="name">
            </div>

            <div class="flex flex-wrap items-center gap-3 mt-3" x-show="!userLocked">
                <button type="button"
                        @click="setAccountMode('new')"
                        class="text-sm font-medium text-(--color-accent) hover:underline cursor-pointer"
                        x-show="accountMode === 'search'">
                    Create new staff account instead
                </button>
                <button type="button"
                        @click="setAccountMode('search')"
                        class="text-sm font-medium text-(--color-text-secondary) hover:text-(--color-text-primary) cursor-pointer"
                        x-show="accountMode === 'new'"
                        x-cloak>
                    Back to search
                </button>
            </div>

            <div x-show="accountMode === 'new' && !userLocked"
                 x-transition.opacity.duration.150ms
                 x-cloak
                 class="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-4 pt-4 border-t border-(--color-border)">
                <div>
                    <label for="admin-email-visible" class="form-label">USM Email <span class="text-(--color-destructive)">*</span></label>
                    <input type="email"
                           id="admin-email-visible"
                           name="email"
                           x-model="email"
                           class="form-input @error('email') form-input-error @enderror"
                           :required="accountMode === 'new'">
                    @error('email') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="admin-name-visible" class="form-label">Full Name</label>
                    <input type="text" id="admin-name-visible" name="name" x-model="name" class="form-input @error('name') form-input-error @enderror">
                    @error('name') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-2">
                    <label for="admin-password" class="form-label">Password</label>
                    <input type="password" name="password" id="admin-password" class="form-input max-w-md @error('password') form-input-error @enderror" placeholder="Required for new staff accounts">
                    @error('password') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                </div>
            </div>
            @error('student_id_number') <p class="form-error mt-2" role="alert">{{ $message }}</p> @enderror
        </div>

        {{-- 2. Role & scope --}}
        <div class="admin-access-zone">
            <div class="admin-access-zone-head">
                <span class="admin-access-zone-step">2</span>
                <span class="admin-access-zone-title">Role &amp; Scope</span>
            </div>

            <div class="grid grid-cols-1 xl:grid-cols-5 gap-5">
                <div class="xl:col-span-2 space-y-3">
                    <p class="text-sm text-(--color-text-secondary)">Select the administrative authority to grant.</p>
                    <div class="admin-access-role-grid" role="radiogroup" aria-label="Admin role">
                        @foreach($adminRoles as $roleOption)
                            @php $meta = $roleMeta[$roleOption] ?? ['short' => $roleOption, 'desc' => '', 'icon' => 'M12 4v16m8-8H4']; @endphp
                            <label class="admin-access-role-card"
                                   :class="{ 'is-active': role === @js($roleOption) }">
                                <input type="radio"
                                       name="role"
                                       value="{{ $roleOption }}"
                                       x-model="role"
                                       @change="syncRole()"
                                       class="sr-only"
                                       @checked(old('role') === $roleOption)>
                                <span class="admin-access-role-icon" aria-hidden="true">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.6" d="{{ $meta['icon'] }}"/>
                                    </svg>
                                </span>
                                <span class="min-w-0">
                                    <span class="block text-sm font-semibold text-(--color-text-primary)">{{ $meta['short'] }}</span>
                                    <span class="block text-xs text-(--color-text-tertiary) mt-0.5 leading-snug">{{ $meta['desc'] }}</span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                    @error('role') <p class="form-error mt-2" role="alert">{{ $message }}</p> @enderror
                </div>

                <div class="xl:col-span-3">
                    <div class="admin-access-scope-empty" x-show="!role" x-cloak>
                        Choose a role to configure organization or college scope.
                    </div>

                    <div class="admin-access-scope-panel space-y-4" x-show="role" x-cloak>
                        <p class="text-sm font-medium text-(--color-text-primary)" x-text="scopeSummary()"></p>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div x-show="roleUsesCollege()" x-cloak>
                                <label for="admin-college" class="form-label">College</label>
                                <select name="college_id"
                                        id="admin-college"
                                        x-model="collegeId"
                                        @change="syncCollege()"
                                        class="form-input form-select @error('college_id') form-input-error @enderror">
                                    <option value="">Select college</option>
                                    <template x-for="college in colleges" :key="college.id">
                                        <option :value="college.id" x-text="`${college.code} — ${college.name}`"></option>
                                    </template>
                                </select>
                                @error('college_id') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                            </div>

                            <div x-show="roleUsesOrganization()" x-cloak>
                                <label for="admin-org" class="form-label">Organization</label>
                                <select name="organization_id"
                                        id="admin-org"
                                        x-model="organizationId"
                                        @change="chooseOrganization()"
                                        :disabled="roleUsesCollege() && !collegeId"
                                        class="form-input form-select @error('organization_id') form-input-error @enderror">
                                    <option value="" x-text="organizationPlaceholder()"></option>
                                    <template x-for="org in filteredOrganizations()" :key="org.id">
                                        <option :value="org.id" x-text="`${org.acronym} — ${org.name}`"></option>
                                    </template>
                                </select>
                                @error('organization_id') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                            </div>
                        </div>

                        <p class="text-xs text-(--color-text-tertiary)" x-show="role === 'USG Admin'" x-cloak>
                            USG scope resolves to the active USG organization when left blank.
                        </p>
                        <p class="text-xs text-(--color-text-tertiary)" x-show="role === 'LSG Admin'" x-cloak>
                            LSG scope resolves from the selected college when organization is left blank.
                        </p>
                    </div>
                </div>
            </div>
        </div>

        {{-- 3. Term --}}
        <div class="admin-access-zone" x-show="role" x-transition.opacity.duration.150ms x-cloak>
            <div class="admin-access-zone-head">
                <span class="admin-access-zone-step">3</span>
                <span class="admin-access-zone-title">Term</span>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <div>
                    <label for="academic-year" class="form-label">Academic Year</label>
                    <input type="text" name="academic_year" id="academic-year" value="{{ old('academic_year', now()->year . '-' . (now()->year + 1)) }}" class="form-input @error('academic_year') form-input-error @enderror">
                    @error('academic_year') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="position-title" class="form-label">Position Title</label>
                    <input type="text" name="position_title" id="position-title" value="{{ old('position_title') }}" placeholder="e.g. President" class="form-input @error('position_title') form-input-error @enderror">
                    @error('position_title') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="term-start" class="form-label">Term Start</label>
                    <input type="date" name="term_start" id="term-start" value="{{ old('term_start') }}" class="form-input @error('term_start') form-input-error @enderror">
                    @error('term_start') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="term-end" class="form-label">Term End</label>
                    <input type="date" name="term_end" id="term-end" value="{{ old('term_end') }}" class="form-input @error('term_end') form-input-error @enderror">
                    @error('term_end') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                </div>
            </div>

            <label class="inline-flex items-center gap-2.5 mt-4 text-sm text-(--color-text-secondary) cursor-pointer">
                <input type="hidden" name="is_primary_admin" value="0">
                <input type="checkbox" name="is_primary_admin" value="1" class="rounded border-(--color-border) text-(--color-accent) focus:ring-(--color-accent)" @checked(old('is_primary_admin', '1') === '1' || old('is_primary_admin') === true)>
                Primary admin for this role and scope in the selected academic year
            </label>
        </div>

        </div>

        <div class="proposal-modal-footer admin-access-assign-footer">
            <p class="admin-access-assign-hint">Assignments update Spatie admin roles only. Revoking or expiring an assignment removes admin access but preserves student profile membership.</p>
            <div class="proposal-footer-actions">
                <button type="button" onclick="document.getElementById('assign-admin-modal')?.close()" class="btn btn-secondary">Cancel</button>
                <button type="submit" class="btn btn-primary btn-lg shrink-0" :disabled="!role">
                    Activate Assignment
                </button>
            </div>
        </div>
    </form>
</dialog>
