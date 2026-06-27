<x-layouts.app :title="'Performance Intelligence — EAES'">

<div x-data="analyticsDashboard()" class="space-y-8">

    {{-- ═══════════════════════════════════════════════════════════════
         PAGE HEADER
    ═══════════════════════════════════════════════════════════════ --}}
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <p class="text-xs font-semibold uppercase tracking-widest text-(--color-accent) mb-1">Performance Intelligence</p>
            <h1 class="text-2xl font-display text-(--color-text-primary)">Performance Analytics Overview</h1>
            <p class="text-sm text-(--color-text-secondary) mt-1">
                Aggregated performance analysis, program-level breakdowns, and multi-scoped filters.
            </p>
        </div>

        {{-- Scope badge --}}
        <div>
            @if($user->hasRole('Super Admin (OSA)'))
                <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-semibold bg-purple-500/10 text-purple-400 border border-purple-500/20">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064"/></svg>
                    University-Wide View
                </span>
            @elseif($user->hasRole('LSG Admin'))
                <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-semibold bg-blue-500/10 text-blue-400 border border-blue-500/20">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
                    College-Level View ({{ $user->organization?->college?->code ?? 'N/A' }})
                </span>
            @else
                <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-semibold bg-green-500/10 text-green-400 border border-green-500/20">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                    Organization View ({{ $user->organization?->acronym ?? 'N/A' }})
                </span>
            @endif
        </div>
    </div>

    {{-- ═══════════════════════════════════════════════════════════════
         FILTER BAR
    ═══════════════════════════════════════════════════════════════ --}}
    <div class="card border border-(--color-border) bg-(--color-surface-raised)" style="border-left: 3px solid var(--color-accent);">
        <div class="card-body p-5">
            <div class="flex items-center gap-2 mb-4">
                <svg class="w-4 h-4 text-(--color-accent)" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/>
                </svg>
                <span class="text-xs font-semibold uppercase tracking-widest text-(--color-text-secondary)">Filter Data</span>
            </div>

            <form method="GET" action="{{ route('dashboard.analytics') }}">
                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-4">
                    {{-- School Year --}}
                    <div>
                        <label for="school_year" class="block text-xs font-semibold text-(--color-text-secondary) mb-1.5 uppercase tracking-wider">School Year</label>
                        <select name="school_year" id="school_year" class="form-input form-select text-sm w-full">
                            <option value="">All School Years</option>
                            @foreach($schoolYears as $sy)
                                <option value="{{ $sy }}" {{ $selectedSchoolYear == $sy ? 'selected' : '' }}>S.Y. {{ $sy }}</option>
                            @endforeach
                        </select>
                    </div>

                    {{-- Semester --}}
                    <div>
                        <label for="semester" class="block text-xs font-semibold text-(--color-text-secondary) mb-1.5 uppercase tracking-wider">Semester</label>
                        <select name="semester" id="semester" class="form-input form-select text-sm w-full">
                            <option value="">All Semesters</option>
                            <option value="1st Semester" {{ $selectedSemester == '1st Semester' ? 'selected' : '' }}>1st Semester</option>
                            <option value="2nd Semester" {{ $selectedSemester == '2nd Semester' ? 'selected' : '' }}>2nd Semester</option>
                            <option value="Summer" {{ $selectedSemester == 'Summer' ? 'selected' : '' }}>Summer</option>
                        </select>
                    </div>

                    {{-- College --}}
                    <div>
                        <label for="college_id" class="block text-xs font-semibold text-(--color-text-secondary) mb-1.5 uppercase tracking-wider">College</label>
                        <select name="college_id" id="college_id" x-model="collegeId"
                                {{ !$user->hasRole('Super Admin (OSA)') ? 'disabled' : '' }}
                                class="form-input form-select text-sm w-full disabled:opacity-60 disabled:cursor-not-allowed">
                            <option value="">All Colleges</option>
                            @foreach($filterColleges as $college)
                                <option value="{{ $college->id }}">{{ $college->code }} — {{ $college->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    {{-- Organization --}}
                    <div>
                        <label for="organization_id" class="block text-xs font-semibold text-(--color-text-secondary) mb-1.5 uppercase tracking-wider">Organization</label>
                        <select name="organization_id" id="organization_id" x-model="orgId"
                                {{ !$user->hasAnyRole(['Super Admin (OSA)', 'LSG Admin']) ? 'disabled' : '' }}
                                class="form-input form-select text-sm w-full disabled:opacity-60 disabled:cursor-not-allowed">
                            <option value="">All Organizations</option>
                            @foreach($filterOrganizations as $org)
                                <option value="{{ $org->id }}" x-show="shouldShowOrg('{{ $org->id }}', '{{ $org->college_id }}')" x-cloak>
                                    {{ $org->acronym }} — {{ $org->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    {{-- Program --}}
                    <div>
                        <label for="program_id" class="block text-xs font-semibold text-(--color-text-secondary) mb-1.5 uppercase tracking-wider">Program</label>
                        <select name="program_id" id="program_id" x-model="progId" class="form-input form-select text-sm w-full">
                            <option value="">All Programs</option>
                            @foreach($filterPrograms as $prog)
                                <option value="{{ $prog->id }}" x-show="shouldShowProgram('{{ $prog->id }}', '{{ $prog->college_id }}')" x-cloak>
                                    {{ $prog->code }} — {{ $prog->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    {{-- Event Type --}}
                    <div>
                        <label for="event_type" class="block text-xs font-semibold text-(--color-text-secondary) mb-1.5 uppercase tracking-wider">Event Type</label>
                        <select name="event_type" id="event_type" class="form-input form-select text-sm w-full">
                            <option value="">All Types</option>
                            <option value="program" {{ $selectedEventType == 'program' ? 'selected' : '' }}>Program</option>
                            <option value="project" {{ $selectedEventType == 'project' ? 'selected' : '' }}>Project</option>
                            <option value="activity" {{ $selectedEventType == 'activity' ? 'selected' : '' }}>Activity</option>
                        </select>
                    </div>
                </div>

                <div class="flex items-center justify-end gap-3 mt-4 pt-4 border-t border-(--color-border)">
                    @if($selectedSchoolYear || $selectedSemester || $selectedCollegeId || $selectedOrgId || $selectedProgramId || $selectedEventType)
                        <a href="{{ route('dashboard.analytics') }}" class="btn btn-secondary btn-sm">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                            Clear Filters
                        </a>
                    @endif
                    <button type="submit" class="btn btn-primary btn-sm">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/>
                        </svg>
                        Apply Filters
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{-- Active Filter Tags --}}
    @if($selectedSchoolYear || $selectedSemester || $selectedCollegeId || $selectedOrgId || $selectedProgramId || $selectedEventType)
        <div class="flex flex-wrap items-center gap-2">
            <span class="text-xs font-semibold uppercase tracking-wider text-(--color-text-tertiary) mr-1">Active:</span>
            @if($selectedSchoolYear)
                <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold bg-purple-500/10 text-purple-400 border border-purple-500/20">
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                    S.Y. {{ $selectedSchoolYear }}
                </span>
            @endif
            @if($selectedSemester)
                <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold bg-purple-500/10 text-purple-400 border border-purple-500/20">{{ $selectedSemester }}</span>
            @endif
            @if($selectedCollegeId && $filterColleges->firstWhere('id', $selectedCollegeId))
                <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold bg-blue-500/10 text-blue-400 border border-blue-500/20">
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16"/></svg>
                    College: {{ $filterColleges->firstWhere('id', $selectedCollegeId)->code }}
                </span>
            @endif
            @if($selectedOrgId && $filterOrganizations->firstWhere('id', $selectedOrgId))
                <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold bg-blue-500/10 text-blue-400 border border-blue-500/20">
                    Org: {{ $filterOrganizations->firstWhere('id', $selectedOrgId)->acronym }}
                </span>
            @endif
            @if($selectedProgramId && $filterPrograms->firstWhere('id', $selectedProgramId))
                <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">
                    Program: {{ $filterPrograms->firstWhere('id', $selectedProgramId)->code }}
                </span>
            @endif
            @if($selectedEventType)
                <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold bg-amber-500/10 text-amber-400 border border-amber-500/20">
                    Type: {{ ucfirst($selectedEventType) }}
                </span>
            @endif
        </div>
    @endif

    {{-- ═══════════════════════════════════════════════════════════════
         KPI SUMMARY STRIP
    ═══════════════════════════════════════════════════════════════ --}}
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div class="stat-card group">
            <div class="flex items-center justify-between mb-3">
                <div class="w-10 h-10 rounded-xl flex items-center justify-center bg-purple-500/10 border border-purple-500/20 group-hover:bg-purple-500/20 transition-colors">
                    <svg class="w-5 h-5 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
                </div>
                <span class="text-xs text-(--color-text-tertiary) font-medium">Organizations</span>
            </div>
            <p class="stat-value text-purple-400">{{ $summary['total_orgs'] }}</p>
            <p class="stat-label">Monitored</p>
        </div>

        <div class="stat-card group">
            <div class="flex items-center justify-between mb-3">
                <div class="w-10 h-10 rounded-xl flex items-center justify-center bg-emerald-500/10 border border-emerald-500/20 group-hover:bg-emerald-500/20 transition-colors">
                    <svg class="w-5 h-5 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                </div>
                <span class="text-xs font-semibold px-2 py-0.5 rounded-full {{ $summary['avg_attendance_rate'] >= 75 ? 'text-emerald-400 bg-emerald-500/10' : ($summary['avg_attendance_rate'] >= 50 ? 'text-amber-400 bg-amber-500/10' : 'text-red-400 bg-red-500/10') }}">
                    {{ $summary['avg_attendance_rate'] >= 75 ? 'Good' : ($summary['avg_attendance_rate'] >= 50 ? 'Fair' : 'Low') }}
                </span>
            </div>
            <p class="stat-value text-emerald-400">{{ $summary['avg_attendance_rate'] }}%</p>
            <p class="stat-label">Avg Attendance Rate</p>
        </div>

        <div class="stat-card group">
            <div class="flex items-center justify-between mb-3">
                <div class="w-10 h-10 rounded-xl flex items-center justify-center bg-amber-500/10 border border-amber-500/20 group-hover:bg-amber-500/20 transition-colors">
                    <svg class="w-5 h-5 text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"/></svg>
                </div>
                <span class="text-xs text-(--color-text-tertiary) font-medium">out of 5.0</span>
            </div>
            <p class="stat-value text-amber-400">{{ $summary['avg_eval_score'] > 0 ? number_format($summary['avg_eval_score'], 2) : '—' }}</p>
            <p class="stat-label">Avg Evaluation Score</p>
        </div>
    </div>

    {{-- ═══════════════════════════════════════════════════════════════
         TAB NAVIGATION
    ═══════════════════════════════════════════════════════════════ --}}
    <div class="flex items-center gap-2 p-1 rounded-xl bg-(--color-surface-raised) border border-(--color-border) w-fit">
        <button @click="activeTab = 'orgs'"
                :class="activeTab === 'orgs'
                    ? 'bg-(--color-accent) text-white shadow-sm'
                    : 'text-(--color-text-secondary) hover:text-(--color-text-primary) hover:bg-(--color-surface-raised)'"
                class="flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium transition-all duration-150 focus:outline-none">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
            Organizations
            <span :class="activeTab === 'orgs' ? 'bg-white/20' : 'bg-(--color-border)'"
                  class="inline-flex items-center justify-center min-w-[20px] h-5 px-1.5 rounded-full text-[10px] font-bold transition-colors">
                {{ $organizations->count() }}
            </span>
        </button>
        <button @click="activeTab = 'programs'"
                :class="activeTab === 'programs'
                    ? 'bg-(--color-accent) text-white shadow-sm'
                    : 'text-(--color-text-secondary) hover:text-(--color-text-primary) hover:bg-(--color-surface-raised)'"
                class="flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium transition-all duration-150 focus:outline-none">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.168.477 4.253 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.253 1.253"/></svg>
            Programs
            <span :class="activeTab === 'programs' ? 'bg-white/20' : 'bg-(--color-border)'"
                  class="inline-flex items-center justify-center min-w-[20px] h-5 px-1.5 rounded-full text-[10px] font-bold transition-colors">
                {{ count($programComparison) }}
            </span>
        </button>
    </div>

    {{-- ═══════════════════════════════════════════════════════════════
         CONTENT AREAS
    ═══════════════════════════════════════════════════════════════ --}}
    @if($organizations->isEmpty())
        <div class="card">
            <div class="empty-state py-16">
                <svg class="empty-state-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                <p class="empty-state-title">No organizations matched</p>
                <p class="empty-state-description">Try adjusting your filters or college selections to see results.</p>
            </div>
        </div>

    @else

        {{-- ────────────────────────────────────────────────────────────
             TAB 1: ORGANIZATIONS VIEW
        ──────────────────────────────────────────────────────────── --}}
        <div x-show="activeTab === 'orgs'" class="space-y-6" x-transition>

            {{-- Performance Leaderboard (Cross-Org) --}}
            @if($ranked->count() > 1)
                <div class="card">
                    <div class="card-header">
                        <div>
                            <h2 class="font-semibold text-(--color-text-primary)">Organization Performance Leaderboard</h2>
                            <p class="text-xs text-(--color-text-secondary) mt-0.5">Ranked by composite index — attendance 50% + evaluation 50%</p>
                        </div>
                        <span class="text-xs text-(--color-text-tertiary) font-medium font-data">{{ $ranked->count() }} orgs</span>
                    </div>
                    <div class="card-body p-0">
                        <div class="overflow-x-auto">
                            <div class="max-h-[420px] overflow-y-auto">
                                <table class="w-full text-sm">
                                    <thead class="sticky top-0 z-10">
                                        <tr class="border-b border-(--color-border) bg-(--color-surface-raised)">
                                            <th class="text-left px-5 py-3 text-xs font-semibold text-(--color-text-tertiary) uppercase tracking-wider w-14">Rank</th>
                                            <th class="text-left px-5 py-3 text-xs font-semibold text-(--color-text-tertiary) uppercase tracking-wider">Organization</th>
                                            <th class="text-right px-5 py-3 text-xs font-semibold text-(--color-text-tertiary) uppercase tracking-wider">Events</th>
                                            <th class="text-right px-5 py-3 text-xs font-semibold text-(--color-text-tertiary) uppercase tracking-wider">Avg Attend.</th>
                                            <th class="text-right px-5 py-3 text-xs font-semibold text-(--color-text-tertiary) uppercase tracking-wider">Valid Rate</th>
                                            <th class="text-right px-5 py-3 text-xs font-semibold text-(--color-text-tertiary) uppercase tracking-wider">Avg Eval</th>
                                            <th class="text-right px-5 py-3 text-xs font-semibold text-(--color-text-tertiary) uppercase tracking-wider">Sentiment</th>
                                            <th class="text-right px-5 py-3 text-xs font-semibold text-(--color-text-tertiary) uppercase tracking-wider">Composite</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-(--color-border)">
                                        @foreach($ranked as $i => $row)
                                        @php
                                            $org = $row['org'];
                                            $rank = $i + 1;
                                        @endphp
                                        <tr class="hover:bg-(--color-surface-raised)/60 transition-colors">
                                            <td class="px-5 py-3">
                                                @if($rank == 1)
                                                    <span class="inline-flex items-center justify-center w-7 h-7 rounded-full bg-amber-500/20 text-amber-500 border border-amber-500/30 text-xs font-bold font-display" title="1st Place">1</span>
                                                @elseif($rank == 2)
                                                    <span class="inline-flex items-center justify-center w-7 h-7 rounded-full bg-slate-400/20 text-slate-400 border border-slate-400/30 text-xs font-bold font-display" title="2nd Place">2</span>
                                                @elseif($rank == 3)
                                                    <span class="inline-flex items-center justify-center w-7 h-7 rounded-full bg-amber-700/20 text-amber-700 border border-amber-700/30 text-xs font-bold font-display" title="3rd Place">3</span>
                                                @else
                                                    <span class="inline-flex items-center justify-center w-7 h-7 rounded-full bg-(--color-surface-raised) text-(--color-text-tertiary) border border-(--color-border) text-xs font-semibold font-display">{{ $rank }}</span>
                                                @endif
                                            </td>
                                            <td class="px-5 py-3">
                                                <div class="flex items-center gap-2.5">
                                                    <div class="w-7 h-7 rounded-lg flex items-center justify-center text-[10px] font-bold text-white shrink-0"
                                                         style="background: linear-gradient(135deg, var(--color-accent), #6366f1);">
                                                        {{ substr($org->acronym ?? $org->name, 0, 2) }}
                                                    </div>
                                                    <div>
                                                        <p class="font-medium text-(--color-text-primary) text-xs leading-snug">{{ $org->name }}</p>
                                                        <p class="text-[10px] text-(--color-text-tertiary)">{{ $org->acronym }} · {{ ucfirst($org->type) }}</p>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="px-5 py-3 text-right font-medium text-(--color-text-primary) font-data text-xs">{{ $row['total_events'] }}</td>
                                            <td class="px-5 py-3 text-right font-data text-xs">
                                                <span class="font-semibold {{ $row['avg_attendance_rate'] >= 75 ? 'text-emerald-400' : ($row['avg_attendance_rate'] >= 50 ? 'text-amber-400' : 'text-red-400') }}">
                                                    {{ $row['avg_attendance_rate'] }}%
                                                </span>
                                            </td>
                                            <td class="px-5 py-3 text-right font-data text-xs">
                                                <span class="font-semibold {{ $row['avg_valid_attendance'] >= 75 ? 'text-emerald-400' : ($row['avg_valid_attendance'] >= 50 ? 'text-amber-400' : 'text-red-400') }}">
                                                    {{ $row['avg_valid_attendance'] }}%
                                                </span>
                                            </td>
                                            <td class="px-5 py-3 text-right font-data text-xs">
                                                @if($row['avg_evaluation_score'] > 0)
                                                    <span class="font-semibold {{ $row['avg_evaluation_score'] >= 4 ? 'text-emerald-400' : ($row['avg_evaluation_score'] >= 3 ? 'text-amber-400' : 'text-red-400') }}">
                                                        {{ number_format($row['avg_evaluation_score'], 2) }}
                                                    </span>
                                                    <span class="text-[10px] text-(--color-text-tertiary)"> /5</span>
                                                @else
                                                    <span class="text-(--color-text-tertiary)">—</span>
                                                @endif
                                            </td>
                                            <td class="px-5 py-3 text-right text-xs">
                                                @if($row['sentiment_positive'] > 0)
                                                    <span class="inline-flex items-center gap-1 text-[10px] font-medium px-2 py-0.5 rounded-full {{ $row['sentiment_positive'] >= 70 ? 'bg-emerald-500/10 text-emerald-500 border border-emerald-500/20' : ($row['sentiment_positive'] >= 50 ? 'bg-amber-500/10 text-amber-500 border border-amber-500/20' : 'bg-red-500/10 text-red-400 border border-red-500/20') }}">
                                                        {{ $row['sentiment_positive'] }}% Pos
                                                    </span>
                                                @else
                                                    <span class="text-(--color-text-tertiary)">—</span>
                                                @endif
                                            </td>
                                            <td class="px-5 py-3 text-right">
                                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-bold"
                                                      style="background: linear-gradient(135deg, rgba(139,92,246,0.15), rgba(99,102,241,0.15)); color: #a78bfa; border: 1px solid rgba(139,92,246,0.2);">
                                                    {{ $row['composite_score'] }}
                                                </span>
                                            </td>
                                        </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            @endif

            {{-- Organization Profiles Grid --}}
            <div x-data="{
                    orgSearch: '',
                    showAll: false,
                    get filteredCount() {
                        if (!this.orgSearch) return {{ $organizations->count() }};
                        return Array.from(document.querySelectorAll('[data-org-name]'))
                            .filter(el => el.dataset.orgName.toLowerCase().includes(this.orgSearch.toLowerCase()))
                            .length;
                    }
                }">

                {{-- Grid toolbar --}}
                <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
                    <div>
                        <h2 class="font-semibold text-(--color-text-primary)">Organization Profiles</h2>
                        <p class="text-xs text-(--color-text-secondary) mt-0.5">Detailed metrics per organization</p>
                    </div>
                    <div class="flex items-center gap-3">
                        <div class="relative">
                            <div class="absolute inset-y-0 left-3 flex items-center pointer-events-none">
                                <svg class="w-3.5 h-3.5 text-(--color-text-tertiary)" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                                </svg>
                            </div>
                            <input type="text"
                                   x-model="orgSearch"
                                   placeholder="Search organizations…"
                                   class="form-input text-sm pl-8 py-1.5 h-9 w-48 focus:w-64 transition-all duration-300">
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 xl:grid-cols-2 gap-5">
                    @foreach($organizations as $orgIndex => $org)
                    @php
                        $m = $orgMetrics[$org->id];
                        $ar = $m['avg_attendance_rate'];
                        $es = $m['avg_evaluation_score'];
                    @endphp
                    <div class="card space-y-0"
                         data-org-name="{{ strtolower($org->name . ' ' . $org->acronym) }}"
                         x-show="!orgSearch || '{{ strtolower($org->name . ' ' . $org->acronym) }}'.includes(orgSearch.toLowerCase())"
                         x-show.combined="showAll || {{ $orgIndex }} < 6"
                         x-data="{ showEvents: false, showPrograms: false }"
                         :class="{ 'hidden': !showAll && {{ $orgIndex }} >= 6 && !orgSearch }"
                         x-transition:enter="transition ease-out duration-200"
                         x-transition:enter-start="opacity-0 translate-y-1"
                         x-transition:enter-end="opacity-100 translate-y-0">

                        {{-- Card Header --}}
                        <div class="card-header flex items-start justify-between gap-3 pb-4">
                            <div class="flex items-start gap-3">
                                <div class="w-10 h-10 rounded-xl flex items-center justify-center text-sm font-bold text-white shrink-0 mt-0.5"
                                     style="background: linear-gradient(135deg, var(--color-accent), #6366f1);">
                                    {{ substr($org->acronym ?? $org->name, 0, 2) }}
                                </div>
                                <div>
                                    <h3 class="font-semibold text-(--color-text-primary)">{{ $org->name }}</h3>
                                    <div class="flex flex-wrap items-center gap-1.5 mt-0.5">
                                        <span class="text-xs text-(--color-text-tertiary)">{{ $org->acronym }}</span>
                                        <span class="inline-block w-1 h-1 rounded-full bg-(--color-border)"></span>
                                        <span class="text-xs px-1.5 py-0.5 rounded font-medium
                                            {{ $org->type === 'society' ? 'bg-blue-500/10 text-blue-400' : ($org->type === 'lsg' ? 'bg-green-500/10 text-green-400' : 'bg-purple-500/10 text-purple-400') }}">
                                            {{ ucfirst($org->type) }}
                                        </span>
                                        @if($org->college)
                                            <span class="text-xs text-(--color-text-tertiary)">· {{ $org->college->name }}</span>
                                        @endif
                                    </div>
                                </div>
                            </div>
                            <div class="shrink-0 flex flex-col items-end gap-1.5">
                                <span class="text-xs px-2 py-1 rounded-lg font-medium bg-(--color-surface-raised) text-(--color-text-secondary) font-data border border-(--color-border)">
                                    {{ $m['total_events'] }} {{ Str::plural('event', $m['total_events']) }}
                                </span>
                                @if($selectedProgramId)
                                    <span class="badge bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 text-[10px] px-1.5 py-0.5 uppercase tracking-wide">Scoped</span>
                                @endif
                            </div>
                        </div>

                        {{-- Card Body --}}
                        <div class="px-5 pb-5 space-y-4">
                            @if($m['total_events'] === 0)
                                <div class="flex items-center gap-2 py-3 text-sm text-(--color-text-tertiary)">
                                    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                    No completed events recorded in current scope.
                                </div>
                            @else

                                {{-- Attendance Progress Bars --}}
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                    <div>
                                        <div class="flex justify-between items-center mb-1.5">
                                            <span class="text-xs font-medium text-(--color-text-secondary)">Avg Attendance</span>
                                            <span class="text-xs font-bold font-data {{ $ar >= 75 ? 'text-emerald-400' : ($ar >= 50 ? 'text-amber-400' : 'text-red-400') }}">{{ $ar }}%</span>
                                        </div>
                                        <div class="h-2 rounded-full bg-(--color-surface-raised) overflow-hidden" title="Average attendance rate: {{ $ar }}%">
                                            <div class="h-full rounded-full transition-all duration-700
                                                {{ $ar >= 75 ? 'bg-emerald-500' : ($ar >= 50 ? 'bg-amber-500' : 'bg-red-500') }}"
                                                 style="width: {{ min($ar, 100) }}%"></div>
                                        </div>
                                    </div>
                                    <div>
                                        <div class="flex justify-between items-center mb-1.5">
                                            <span class="text-xs font-medium text-(--color-text-secondary)">Valid (Eval Gate)</span>
                                            <span class="text-xs font-bold font-data {{ $m['avg_valid_attendance'] >= 75 ? 'text-emerald-400' : ($m['avg_valid_attendance'] >= 50 ? 'text-amber-400' : 'text-red-400') }}">{{ $m['avg_valid_attendance'] }}%</span>
                                        </div>
                                        <div class="h-2 rounded-full bg-(--color-surface-raised) overflow-hidden" title="Valid attendance rate: {{ $m['avg_valid_attendance'] }}%">
                                            <div class="h-full rounded-full transition-all duration-700
                                                {{ $m['avg_valid_attendance'] >= 75 ? 'bg-emerald-500' : ($m['avg_valid_attendance'] >= 50 ? 'bg-amber-500' : 'bg-red-500') }}"
                                                 style="width: {{ min($m['avg_valid_attendance'], 100) }}%"></div>
                                        </div>
                                    </div>
                                </div>

                                {{-- Quick stats row --}}
                                <div class="grid grid-cols-4 gap-2 bg-(--color-surface-raised) border border-(--color-border) p-3 rounded-xl">
                                    <div class="text-center">
                                        <p class="text-sm font-data font-semibold text-(--color-text-primary)">{{ $m['total_members'] }}</p>
                                        <p class="text-[10px] text-(--color-text-tertiary) uppercase tracking-wider mt-0.5">Enrolled</p>
                                    </div>
                                    <div class="text-center border-l border-(--color-border)">
                                        <p class="text-sm font-data font-semibold text-(--color-text-primary)">{{ $m['active_members'] }}</p>
                                        <p class="text-[10px] text-(--color-text-tertiary) uppercase tracking-wider mt-0.5">Active</p>
                                    </div>
                                    <div class="text-center border-l border-(--color-border)">
                                        <p class="text-sm font-data font-semibold {{ $es >= 4 ? 'text-emerald-400' : ($es >= 3 ? 'text-amber-400' : 'text-red-400') }}">{{ $es > 0 ? number_format($es, 2) : '—' }}</p>
                                        <p class="text-[10px] text-(--color-text-tertiary) uppercase tracking-wider mt-0.5">Eval Score</p>
                                    </div>
                                    <div class="text-center border-l border-(--color-border)">
                                        <p class="text-sm font-data font-semibold text-(--color-text-primary)">{{ $m['sentiment_positive'] }}%</p>
                                        <p class="text-[10px] text-(--color-text-tertiary) uppercase tracking-wider mt-0.5">Positive</p>
                                    </div>
                                </div>

                                {{-- Sentiment Stacked Bar --}}
                                @php
                                    $neutralPct = max(0, 100 - $m['sentiment_positive'] - $m['sentiment_negative']);
                                @endphp
                                @if($m['total_events'] > 0)
                                <div class="space-y-1.5">
                                    <div class="flex justify-between items-center text-[11px]">
                                        <span class="text-(--color-text-secondary) font-medium">Comment Sentiments ({{ config('services.ai.provider') === 'ollama' ? 'Ollama' : 'Gemini' }} NLP)</span>
                                        <span class="font-semibold text-emerald-500 font-data">{{ $m['sentiment_positive'] }}% Positive</span>
                                    </div>
                                    <div class="h-2 w-full rounded-full bg-(--color-surface-inset) overflow-hidden flex gap-px"
                                         title="Positive: {{ $m['sentiment_positive'] }}% · Neutral: {{ $neutralPct }}% · Negative: {{ $m['sentiment_negative'] }}%">
                                        <div class="h-full bg-emerald-500 transition-all duration-500" style="width: {{ $m['sentiment_positive'] }}%"></div>
                                        <div class="h-full bg-slate-400/50 transition-all duration-500" style="width: {{ $neutralPct }}%"></div>
                                        <div class="h-full bg-rose-500 transition-all duration-500" style="width: {{ $m['sentiment_negative'] }}%"></div>
                                    </div>
                                    <div class="flex items-center gap-3 text-[10px] text-(--color-text-tertiary)">
                                        <span class="flex items-center gap-1"><span class="w-1.5 h-1.5 rounded-full bg-emerald-500 inline-block"></span>Positive</span>
                                        <span class="flex items-center gap-1"><span class="w-1.5 h-1.5 rounded-full bg-slate-400 inline-block"></span>Neutral</span>
                                        <span class="flex items-center gap-1"><span class="w-1.5 h-1.5 rounded-full bg-rose-500 inline-block"></span>Negative</span>
                                    </div>
                                </div>
                                @endif

                                {{-- Punctuality Aggregates --}}
                                <div class="grid grid-cols-3 gap-2 border border-(--color-border) p-3 rounded-xl text-center text-xs">
                                    <div>
                                        <p class="text-amber-400 font-semibold font-data text-base">{{ $m['total_late'] }}</p>
                                        <p class="text-(--color-text-secondary) text-[10px] mt-0.5">Late Scans</p>
                                    </div>
                                    <div class="border-l border-(--color-border)">
                                        <p class="text-red-400 font-semibold font-data text-base">{{ $m['total_absent'] }}</p>
                                        <p class="text-(--color-text-secondary) text-[10px] mt-0.5">Absents</p>
                                    </div>
                                    <div class="border-l border-(--color-border)">
                                        <p class="text-purple-400 font-semibold font-data text-base">{{ $m['total_left_early'] }}</p>
                                        <p class="text-(--color-text-secondary) text-[10px] mt-0.5">Left Early</p>
                                    </div>
                                </div>

                                {{-- Expansion Toggle Buttons --}}
                                <div class="flex items-center gap-2 pt-1">
                                    @if(!$selectedProgramId && count($m['linked_programs_metrics']) > 0)
                                        <button @click="showPrograms = !showPrograms; showEvents = false"
                                                :class="showPrograms ? 'bg-(--color-accent)/10 text-(--color-accent) border-(--color-accent)/30' : 'bg-(--color-surface-raised) text-(--color-text-secondary) border-(--color-border) hover:text-(--color-text-primary)'"
                                                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold border transition-all cursor-pointer">
                                            <svg class="w-3.5 h-3.5 transition-transform duration-200" :class="{ 'rotate-90': showPrograms }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/>
                                            </svg>
                                            Programs ({{ count($m['linked_programs_metrics']) }})
                                        </button>
                                    @endif

                                    @if(count($m['event_breakdown']) > 0)
                                        <button @click="showEvents = !showEvents; showPrograms = false"
                                                :class="showEvents ? 'bg-(--color-accent)/10 text-(--color-accent) border-(--color-accent)/30' : 'bg-(--color-surface-raised) text-(--color-text-secondary) border-(--color-border) hover:text-(--color-text-primary)'"
                                                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold border transition-all cursor-pointer">
                                            <svg class="w-3.5 h-3.5 transition-transform duration-200" :class="{ 'rotate-90': showEvents }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/>
                                            </svg>
                                            Events ({{ count($m['event_breakdown']) }})
                                        </button>
                                    @endif
                                </div>

                                {{-- Programs breakdown table --}}
                                <div x-show="showPrograms" x-collapse x-cloak class="border border-(--color-border) rounded-xl overflow-hidden">
                                    <div class="max-h-[280px] overflow-y-auto">
                                        <table class="w-full text-xs">
                                            <thead class="sticky top-0 z-10">
                                                <tr class="bg-(--color-surface-raised) border-b border-(--color-border)">
                                                    <th class="text-left px-4 py-2.5 font-semibold text-(--color-text-tertiary) uppercase tracking-wider">Code</th>
                                                    <th class="text-left px-4 py-2.5 font-semibold text-(--color-text-tertiary) uppercase tracking-wider">Program</th>
                                                    <th class="text-right px-4 py-2.5 font-semibold text-(--color-text-tertiary) uppercase tracking-wider">Attend.</th>
                                                    <th class="text-right px-4 py-2.5 font-semibold text-(--color-text-tertiary) uppercase tracking-wider">Eval</th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-(--color-border)">
                                                @foreach($m['linked_programs_metrics'] as $pm)
                                                <tr class="hover:bg-(--color-surface-raised)/60 transition-colors">
                                                    <td class="px-4 py-2 font-semibold text-(--color-text-primary) font-data">{{ $pm['program']->code }}</td>
                                                    <td class="px-4 py-2 text-(--color-text-secondary) truncate max-w-[140px]">{{ $pm['program']->name }}</td>
                                                    <td class="px-4 py-2 text-right font-semibold font-data {{ $pm['avg_attendance_rate'] >= 75 ? 'text-emerald-400' : ($pm['avg_attendance_rate'] >= 50 ? 'text-amber-400' : 'text-red-400') }}">{{ $pm['avg_attendance_rate'] }}%</td>
                                                    <td class="px-4 py-2 text-right font-semibold font-data {{ $pm['avg_evaluation_score'] >= 4 ? 'text-emerald-400' : ($pm['avg_evaluation_score'] >= 3 ? 'text-amber-400' : 'text-red-400') }}">{{ $pm['avg_evaluation_score'] > 0 ? number_format($pm['avg_evaluation_score'], 2) : '—' }}</td>
                                                </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                </div>

                                {{-- Events breakdown table --}}
                                <div x-show="showEvents" x-collapse x-cloak class="border border-(--color-border) rounded-xl overflow-hidden">
                                    <div class="max-h-[280px] overflow-y-auto">
                                        <table class="w-full text-xs">
                                            <thead class="sticky top-0 z-10">
                                                <tr class="bg-(--color-surface-raised) border-b border-(--color-border)">
                                                    <th class="text-left px-4 py-2.5 font-semibold text-(--color-text-tertiary) uppercase tracking-wider">Event</th>
                                                    <th class="text-right px-4 py-2.5 font-semibold text-(--color-text-tertiary) uppercase tracking-wider">Date</th>
                                                    <th class="text-right px-4 py-2.5 font-semibold text-(--color-text-tertiary) uppercase tracking-wider">Attend.</th>
                                                    <th class="text-right px-4 py-2.5 font-semibold text-(--color-text-tertiary) uppercase tracking-wider">Eval</th>
                                                    <th class="text-right px-4 py-2.5 font-semibold text-(--color-text-tertiary) uppercase tracking-wider"></th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-(--color-border)">
                                                @foreach($m['event_breakdown'] as $ev)
                                                <tr class="hover:bg-(--color-surface-raised)/60 transition-colors">
                                                    <td class="px-4 py-2.5 font-medium text-(--color-text-primary) max-w-[160px] truncate">{{ $ev['title'] }}</td>
                                                    <td class="px-4 py-2.5 text-right text-(--color-text-secondary) font-data">{{ $ev['start_date'] }}</td>
                                                    <td class="px-4 py-2.5 text-right font-semibold font-data {{ $ev['attendance_rate'] >= 75 ? 'text-emerald-400' : ($ev['attendance_rate'] >= 50 ? 'text-amber-400' : 'text-red-400') }}">{{ $ev['attendance_rate'] }}%</td>
                                                    <td class="px-4 py-2.5 text-right font-semibold font-data {{ $ev['eval_score'] >= 4 ? 'text-emerald-400' : ($ev['eval_score'] >= 3 ? 'text-amber-400' : 'text-red-400') }}">{{ $ev['eval_score'] > 0 ? number_format($ev['eval_score'], 2) : '—' }}</td>
                                                    <td class="px-4 py-2.5 text-right">
                                                        <button type="button"
                                                                @click="$dispatch('open-analytics', { id: '{{ $ev['id'] }}', title: '{{ addslashes($ev['title']) }}' })"
                                                                class="inline-flex items-center gap-0.5 text-xs text-(--color-accent) hover:underline font-semibold cursor-pointer transition-opacity hover:opacity-80">
                                                            Details →
                                                        </button>
                                                    </td>
                                                </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                </div>

                            @endif
                        </div>
                    </div>
                    @endforeach
                </div>

                {{-- Show More / Show Less toggle --}}
                @if($organizations->count() > 6)
                <div class="text-center mt-4" x-show="!orgSearch">
                    <button @click="showAll = !showAll"
                            class="inline-flex items-center gap-2 px-5 py-2 rounded-xl text-sm font-semibold border border-(--color-border) bg-(--color-surface-raised) text-(--color-text-secondary) hover:text-(--color-text-primary) hover:border-(--color-accent) transition-all cursor-pointer">
                        <svg class="w-4 h-4 transition-transform duration-200" :class="{ 'rotate-180': showAll }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                        </svg>
                        <span x-text="showAll ? 'Show fewer organizations' : 'Show all {{ $organizations->count() }} organizations'"></span>
                    </button>
                    <p x-show="!showAll" class="text-xs text-(--color-text-tertiary) mt-2">Showing 6 of {{ $organizations->count() }}</p>
                </div>
                @endif
            </div>
        </div>

        {{-- ────────────────────────────────────────────────────────────
             TAB 2: PROGRAM COMPARISONS VIEW
        ──────────────────────────────────────────────────────────── --}}
        <div x-show="activeTab === 'programs'" class="space-y-6" x-transition x-cloak>
            <div class="card">
                <div class="card-header">
                    <div>
                        <h2 class="font-semibold text-(--color-text-primary)">Academic Programs Leaderboard</h2>
                        <p class="text-xs text-(--color-text-secondary) mt-0.5">Student attendance and evaluation feedback sorted by performance composite</p>
                    </div>
                    <span class="text-xs text-(--color-text-tertiary) font-medium font-data">{{ count($programComparison) }} programs</span>
                </div>
                <div class="card-body p-0">
                    <div class="overflow-x-auto">
                        <div class="max-h-[560px] overflow-y-auto">
                            <table class="w-full text-sm">
                                <thead class="sticky top-0 z-10">
                                    <tr class="border-b border-(--color-border) bg-(--color-surface-raised)">
                                        <th class="text-left px-5 py-3 text-xs font-semibold text-(--color-text-tertiary) uppercase tracking-wider w-14">Rank</th>
                                        <th class="text-left px-5 py-3 text-xs font-semibold text-(--color-text-tertiary) uppercase tracking-wider">Program</th>
                                        <th class="text-left px-5 py-3 text-xs font-semibold text-(--color-text-tertiary) uppercase tracking-wider">College</th>
                                        <th class="text-right px-5 py-3 text-xs font-semibold text-(--color-text-tertiary) uppercase tracking-wider">Students</th>
                                        <th class="text-right px-5 py-3 text-xs font-semibold text-(--color-text-tertiary) uppercase tracking-wider">Events</th>
                                        <th class="text-right px-5 py-3 text-xs font-semibold text-(--color-text-tertiary) uppercase tracking-wider">Avg Attend.</th>
                                        <th class="text-right px-5 py-3 text-xs font-semibold text-(--color-text-tertiary) uppercase tracking-wider">Valid Rate</th>
                                        <th class="text-right px-5 py-3 text-xs font-semibold text-(--color-text-tertiary) uppercase tracking-wider">Avg Eval</th>
                                        <th class="text-right px-5 py-3 text-xs font-semibold text-(--color-text-tertiary) uppercase tracking-wider">Late/Abs/Left-E</th>
                                        <th class="text-right px-5 py-3 text-xs font-semibold text-(--color-text-tertiary) uppercase tracking-wider">Composite</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-(--color-border)">
                                    @foreach($programComparison as $i => $progMetrics)
                                    @php
                                        $prog = $progMetrics['program'];
                                        $rank = $i + 1;
                                    @endphp
                                    <tr class="hover:bg-(--color-surface-raised)/60 transition-colors">
                                        <td class="px-5 py-3">
                                            @if($rank == 1)
                                                <span class="inline-flex items-center justify-center w-7 h-7 rounded-full bg-amber-500/20 text-amber-500 border border-amber-500/30 text-xs font-bold font-display" title="1st Place">1</span>
                                            @elseif($rank == 2)
                                                <span class="inline-flex items-center justify-center w-7 h-7 rounded-full bg-slate-400/20 text-slate-400 border border-slate-400/30 text-xs font-bold font-display" title="2nd Place">2</span>
                                            @elseif($rank == 3)
                                                <span class="inline-flex items-center justify-center w-7 h-7 rounded-full bg-amber-700/20 text-amber-700 border border-amber-700/30 text-xs font-bold font-display" title="3rd Place">3</span>
                                            @else
                                                <span class="inline-flex items-center justify-center w-7 h-7 rounded-full bg-(--color-surface-raised) text-(--color-text-tertiary) border border-(--color-border) text-xs font-semibold font-display">{{ $rank }}</span>
                                            @endif
                                        </td>
                                        <td class="px-5 py-3">
                                            <p class="font-semibold text-(--color-text-primary) text-xs">{{ $prog->code }}</p>
                                            <p class="text-[10px] text-(--color-text-tertiary) max-w-[180px] truncate">{{ $prog->name }}</p>
                                        </td>
                                        <td class="px-5 py-3 text-xs font-semibold text-(--color-text-secondary)">{{ $prog->college->code }}</td>
                                        <td class="px-5 py-3 text-right font-data text-xs text-(--color-text-primary)">{{ $progMetrics['total_members'] }}</td>
                                        <td class="px-5 py-3 text-right font-data text-xs text-(--color-text-primary)">{{ $progMetrics['total_events'] }}</td>
                                        <td class="px-5 py-3 text-right font-data text-xs">
                                            <span class="font-semibold {{ $progMetrics['avg_attendance_rate'] >= 75 ? 'text-emerald-400' : ($progMetrics['avg_attendance_rate'] >= 50 ? 'text-amber-400' : 'text-red-400') }}">
                                                {{ $progMetrics['avg_attendance_rate'] }}%
                                            </span>
                                        </td>
                                        <td class="px-5 py-3 text-right font-data text-xs">
                                            <span class="font-semibold {{ $progMetrics['avg_valid_attendance'] >= 75 ? 'text-emerald-400' : ($progMetrics['avg_valid_attendance'] >= 50 ? 'text-amber-400' : 'text-red-400') }}">
                                                {{ $progMetrics['avg_valid_attendance'] }}%
                                            </span>
                                        </td>
                                        <td class="px-5 py-3 text-right font-data text-xs">
                                            @if($progMetrics['avg_evaluation_score'] > 0)
                                                <span class="font-semibold {{ $progMetrics['avg_evaluation_score'] >= 4 ? 'text-emerald-400' : ($progMetrics['avg_evaluation_score'] >= 3 ? 'text-amber-400' : 'text-red-400') }}">
                                                    {{ number_format($progMetrics['avg_evaluation_score'], 2) }}
                                                </span>
                                                <span class="text-[10px] text-(--color-text-tertiary)"> /5</span>
                                            @else
                                                <span class="text-(--color-text-tertiary)">—</span>
                                            @endif
                                        </td>
                                        <td class="px-5 py-3 text-right text-xs font-data">
                                            <span class="text-amber-400 font-semibold">{{ $progMetrics['total_late'] }}</span>
                                            <span class="text-(--color-border) mx-0.5">/</span>
                                            <span class="text-red-400 font-semibold">{{ $progMetrics['total_absent'] }}</span>
                                            <span class="text-(--color-border) mx-0.5">/</span>
                                            <span class="text-purple-400 font-semibold">{{ $progMetrics['total_left_early'] }}</span>
                                        </td>
                                        <td class="px-5 py-3 text-right">
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-bold"
                                                  style="background: linear-gradient(135deg, rgba(139,92,246,0.15), rgba(99,102,241,0.15)); color: #a78bfa; border: 1px solid rgba(139,92,246,0.2);">
                                                {{ $progMetrics['composite_score'] }}
                                            </span>
                                        </td>
                                    </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    @endif
</div>

{{-- ═══════════════════════════════════════════════════════════════
     REAL-TIME ANALYTICS MODAL
═══════════════════════════════════════════════════════════════ --}}
<dialog id="analytics-modal"
        class="proposal-modal modal-md"
        aria-labelledby="analytics-modal-title"
        aria-describedby="analytics-modal-description"
        x-data="analyticsModal"
        @open-analytics.window="openModal($event.detail)"
        onclick="if (event.target === this) closeModal()">
    <div class="proposal-modal-form" onclick="event.stopPropagation()">
        <div class="proposal-modal-header">
            <div class="proposal-modal-title-row">
                <span class="proposal-modal-icon" aria-hidden="true">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                </span>
                <div>
                    <p class="proposal-modal-kicker" x-text="eventTitle || 'Event Analytics'"></p>
                    <h2 id="analytics-modal-title" class="proposal-modal-title">Real-Time Event Analytics</h2>
                    <p id="analytics-modal-description" class="proposal-modal-subtitle">Aggregated evaluation rates, sentiment scores, and participant demographics.</p>
                </div>
            </div>
            <button type="button" @click="closeModal()" class="btn-icon btn-ghost" aria-label="Close analytics modal">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>

        <div class="proposal-modal-body">
            {{-- Loading State --}}
            <div x-show="loading" class="py-12 flex flex-col items-center justify-center gap-3">
                <div class="w-8 h-8 rounded-full border-4 border-(--color-accent-light) border-t-(--color-accent) animate-spin"></div>
                <p class="text-sm text-(--color-text-secondary)">Retrieving real-time analytics…</p>
            </div>

            {{-- Content State --}}
            <div x-show="!loading" class="space-y-4">
                {{-- Attendance & Evaluations --}}
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div class="proposal-modal-section">
                        <h3 class="font-semibold text-sm text-(--color-text-primary) mb-3 flex items-center gap-2">
                            <svg class="w-4 h-4 text-(--color-accent)" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                            Attendance Rate
                        </h3>
                        <div class="flex items-baseline gap-2 mb-2">
                            <span class="text-2xl font-bold text-(--color-text-primary)" x-text="(attendance.attendance_rate || 0) + '%'"></span>
                            <span class="text-xs text-(--color-text-tertiary)" x-text="'(' + (attendance.attended || 0) + ' / ' + (attendance.total_demographic || 0) + ' students)'"></span>
                        </div>
                        <div class="w-full bg-(--color-surface-raised) h-2 rounded-full overflow-hidden">
                            <div class="bg-(--color-accent) h-full transition-all duration-500" :style="'width: ' + (attendance.attendance_rate || 0) + '%'"></div>
                        </div>
                    </div>

                    <div class="proposal-modal-section">
                        <h3 class="font-semibold text-sm text-(--color-text-primary) mb-3 flex items-center gap-2">
                            <svg class="w-4 h-4 text-amber-500" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
                            Evaluation Rating
                        </h3>
                        <div class="flex items-baseline gap-2">
                            <span class="text-2xl font-bold text-(--color-text-primary)" x-text="(evaluations.average_rating || '0.00')"></span>
                            <span class="text-xs text-(--color-text-tertiary)">/ 5.00 average</span>
                        </div>
                        <p class="text-xs text-(--color-text-tertiary) mt-1" x-text="'Total evaluations: ' + (evaluations.total_submitted || 0)"></p>
                    </div>
                </div>

                {{-- Comment Sentiments --}}
                <div class="proposal-modal-section">
                    <h3 class="font-semibold text-sm text-(--color-text-primary) mb-3 flex items-center gap-2">
                        <svg class="w-4 h-4 text-(--color-accent)" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"/></svg>
                        Comment Sentiments
                        <span class="ml-auto text-[10px] font-normal text-(--color-text-tertiary) inline-flex items-center gap-1">
                            <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 3.75L8.25 7.5 4.5 9l3.75 1.5 1.5 3.75 1.5-3.75L15 9l-3.75-1.5-1.5-3.75z"/></svg>
                            {{ config('services.ai.provider') === 'ollama' ? 'Ollama' : 'Gemini' }} NLP
                        </span>
                    </h3>
                    <div class="space-y-2.5">
                        <div>
                            <div class="flex justify-between text-xs text-(--color-text-secondary) mb-1.5">
                                <span class="font-medium text-emerald-500 flex items-center gap-1">
                                    <span class="w-2 h-2 rounded-full bg-emerald-500 inline-block"></span>Positive
                                </span>
                                <span x-text="evaluations.sentiments.positive + ' comment(s) · ' + getPercentage(evaluations.sentiments.positive, evaluations.total_submitted) + '%'"></span>
                            </div>
                            <div class="w-full bg-(--color-surface-raised) h-2 rounded-full overflow-hidden">
                                <div class="bg-emerald-500 h-full transition-all duration-500" :style="'width: ' + getPercentage(evaluations.sentiments.positive, evaluations.total_submitted) + '%'"></div>
                            </div>
                        </div>
                        <div>
                            <div class="flex justify-between text-xs text-(--color-text-secondary) mb-1.5">
                                <span class="font-medium text-slate-400 flex items-center gap-1">
                                    <span class="w-2 h-2 rounded-full bg-slate-400 inline-block"></span>Neutral
                                </span>
                                <span x-text="evaluations.sentiments.neutral + ' comment(s) · ' + getPercentage(evaluations.sentiments.neutral, evaluations.total_submitted) + '%'"></span>
                            </div>
                            <div class="w-full bg-(--color-surface-raised) h-2 rounded-full overflow-hidden">
                                <div class="bg-slate-400 h-full transition-all duration-500" :style="'width: ' + getPercentage(evaluations.sentiments.neutral, evaluations.total_submitted) + '%'"></div>
                            </div>
                        </div>
                        <div>
                            <div class="flex justify-between text-xs text-(--color-text-secondary) mb-1.5">
                                <span class="font-medium text-rose-400 flex items-center gap-1">
                                    <span class="w-2 h-2 rounded-full bg-rose-500 inline-block"></span>Negative
                                </span>
                                <span x-text="evaluations.sentiments.negative + ' comment(s) · ' + getPercentage(evaluations.sentiments.negative, evaluations.total_submitted) + '%'"></span>
                            </div>
                            <div class="w-full bg-(--color-surface-raised) h-2 rounded-full overflow-hidden">
                                <div class="bg-rose-500 h-full transition-all duration-500" :style="'width: ' + getPercentage(evaluations.sentiments.negative, evaluations.total_submitted) + '%'"></div>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Demographic Breakdown --}}
                <div class="proposal-modal-section">
                    <h3 class="font-semibold text-sm text-(--color-text-primary) mb-3 flex items-center gap-2">
                        <svg class="w-4 h-4 text-(--color-accent)" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
                        Participant Demographic Breakdown
                    </h3>
                    <div class="flex flex-wrap gap-2">
                        <template x-for="demo in demographics" :key="demo.program_code">
                            <div class="px-3 py-1.5 rounded-lg border border-(--color-border) bg-(--color-surface-raised) flex items-center gap-2 text-xs">
                                <span class="font-bold text-(--color-accent)" x-text="demo.program_code || 'N/A'"></span>
                                <span class="w-px h-3 bg-white/20"></span>
                                <span class="text-(--color-text-secondary)" x-text="demo.count + ' student(s)'"></span>
                            </div>
                        </template>
                        <div x-show="demographics.length === 0" class="text-xs text-(--color-text-tertiary) italic">
                            No demographic details available yet.
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="proposal-modal-footer">
            <p class="proposal-required-note">Real-time attendance & feedback metrics</p>
            <div class="proposal-footer-actions">
                <button type="button" @click="closeModal()" class="btn btn-secondary">Close</button>
            </div>
        </div>
    </div>
</dialog>

{{-- Alpine.js Data --}}
<script>
    document.addEventListener('alpine:init', () => {
        window.Alpine.data('analyticsDashboard', () => ({
            activeTab: 'orgs',

            orgsByCollege: @js($orgsByCollege),
            programsByCollege: @js($programsByCollege),
            programsByOrg: @js($programsByOrg),

            collegeId: @js($selectedCollegeId ?? ''),
            orgId: @js($selectedOrgId ?? ''),
            progId: @js($selectedProgramId ?? ''),

            init() {
                this.$watch('collegeId', () => {
                    this.orgId = '';
                    this.progId = '';
                });
                this.$watch('orgId', () => {
                    this.progId = '';
                });
            },

            shouldShowOrg(orgId, collegeId) {
                if (this.collegeId) return this.collegeId === collegeId;
                return true;
            },

            shouldShowProgram(progId, collegeId) {
                if (this.orgId) {
                    const linked = this.programsByOrg[this.orgId] || [];
                    return linked.some(p => p.id === progId);
                }
                if (this.collegeId) return this.collegeId === collegeId;
                return true;
            }
        }));

        window.Alpine.data('analyticsModal', () => ({
            loading: false,
            eventId: null,
            eventTitle: '',
            attendance: { total_demographic: 0, attended: 0, attendance_rate: 0 },
            evaluations: { total_submitted: 0, average_rating: 0, sentiments: { positive: 0, neutral: 0, negative: 0 } },
            demographics: [],

            async openModal(detail) {
                this.eventId = detail.id;
                this.eventTitle = detail.title;
                this.loading = true;

                const modal = document.getElementById('analytics-modal');
                if (modal?.showModal && !modal.open) modal.showModal();

                try {
                    const response = await fetch(`/dashboard/events/${this.eventId}/analytics`);
                    if (!response.ok) throw new Error('Failed to retrieve analytics');
                    const data = await response.json();
                    this.attendance = data.attendance;
                    this.evaluations = data.evaluations;
                    this.demographics = data.demographics || [];
                } catch (e) {
                    console.error(e);
                    alert('Could not retrieve real-time analytics data. Please try again.');
                    this.closeModal();
                } finally {
                    this.loading = false;
                }
            },

            closeModal() {
                const modal = document.getElementById('analytics-modal');
                if (modal?.open) modal.close();
                this.eventId = null;
                this.eventTitle = '';
            },

            getPercentage(value, total) {
                if (!total || total <= 0) return 0;
                return Math.round((value / total) * 100);
            }
        }));
    });
</script>

</x-layouts.app>
