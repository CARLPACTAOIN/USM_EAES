<x-layouts.app :title="'Workflow Templates - EAES'">
    @php
        $stats = $stats ?? [
            'total_templates' => $templates->total(),
            'active_versions' => \App\Models\ProposalProcessTemplateVersion::query()->where('status', \App\Models\ProposalProcessTemplateVersion::STATUS_PUBLISHED)->count(),
            'draft_versions' => \App\Models\ProposalProcessTemplateVersion::query()->where('status', \App\Models\ProposalProcessTemplateVersion::STATUS_DRAFT)->count(),
            'total_runs' => \App\Models\ProposalWorkflowRun::query()->count(),
        ];

        $targetVersionId = request('version');

        $statusBadgeClasses = [
            'draft' => 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-800/40 dark:bg-amber-950/30 dark:text-amber-300',
            'published' => 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-800/40 dark:bg-emerald-950/30 dark:text-emerald-300',
            'retired' => 'border-slate-200 bg-slate-100 text-slate-700 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300',
            'active' => 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-800/40 dark:bg-emerald-950/30 dark:text-emerald-300',
            'archived' => 'border-slate-200 bg-slate-100 text-slate-700 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300',
        ];

        $typeColors = [
            'endorsement' => 'text-indigo-700 bg-indigo-50 border-indigo-200 dark:text-indigo-300 dark:bg-indigo-950/40 dark:border-indigo-800/40',
            'clearance' => 'text-purple-700 bg-purple-50 border-purple-200 dark:text-purple-300 dark:bg-purple-950/40 dark:border-purple-800/40',
            'review' => 'text-amber-700 bg-amber-50 border-amber-200 dark:text-amber-300 dark:bg-amber-950/40 dark:border-amber-800/40',
            'approval' => 'text-emerald-700 bg-emerald-50 border-emerald-200 dark:text-emerald-300 dark:bg-emerald-950/40 dark:border-emerald-800/40',
            'intake' => 'text-sky-700 bg-sky-50 border-sky-200 dark:text-sky-300 dark:bg-sky-950/40 dark:border-sky-800/40',
        ];

        $roleLabels = [
            'adviser' => 'Faculty Adviser',
            'dept_chair' => 'Department Chairperson',
            'college_dean' => 'College Dean',
            'chief_security' => 'Chief of Campus Security',
            'osa' => 'Office of Student Affairs (OSA)',
            'osas_director' => 'OSAS Director',
            'vp_academic_affairs' => 'VP for Academic Affairs',
            'university_president' => 'University President',
        ];

        $scopeLabels = [
            'organization' => 'Club / Organization Level',
            'department' => 'Department Level',
            'college' => 'College Level',
            'university' => 'Campus / University-Wide',
        ];

        $stepTypeLabels = [
            'endorsement' => 'Endorsement',
            'clearance' => 'Clearance',
            'review' => 'Document Review',
            'approval' => 'Official Approval',
            'intake' => 'Submission Intake',
        ];
    @endphp

    <div class="workflow-templates-page"
         x-data="{
             searchQuery: '',
             statusFilter: 'all',
             matchesFilter(template) {
                 const q = this.searchQuery.toLowerCase().trim();
                 const name = (template.name || '').toLowerCase();
                 const desc = (template.description || '').toLowerCase();
                 const matchesQuery = !q || name.includes(q) || desc.includes(q);

                 if (!matchesQuery) return false;

                 if (this.statusFilter === 'all') return true;
                 if (this.statusFilter === 'active') return template.status === 'active';
                 if (this.statusFilter === 'archived') return template.status === 'archived';
                 if (this.statusFilter === 'has_draft') return template.hasDraft === true;
                 return true;
             }
         }">

        {{-- Page Header --}}
        <header class="admin-access-hero">
            <div class="admin-access-hero-main">
                <div class="admin-access-assign-icon" aria-hidden="true">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 6.75h11.25M9 12h11.25M9 17.25h11.25M3.75 6.75h.008v.008H3.75V6.75zm0 5.25h.008v.008H3.75V12zm0 5.25h.008v.008H3.75v-.008z"/>
                    </svg>
                </div>
                <div>
                    <p class="portal-page-kicker">OSA Student Services &middot; Proposal Approvals</p>
                    <h1 class="text-2xl font-display text-(--color-text-primary)">Workflow Templates</h1>
                    <p class="text-sm text-(--color-text-secondary) mt-1 max-w-2xl">
                        Customize how student event proposals are reviewed and approved. Choose who signs off on each proposal, set the approval sequence, and specify which student groups follow each workflow.
                    </p>
                </div>
            </div>
            <div class="admin-access-hero-actions">
                <button type="button"
                        onclick="document.getElementById('workflow-help-modal')?.showModal()"
                        class="btn btn-secondary cursor-pointer"
                        title="Learn how proposal approval workflows work">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <span>How It Works</span>
                </button>
                <button type="button"
                        onclick="document.getElementById('create-template-modal')?.showModal()"
                        class="btn btn-primary cursor-pointer">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    <span>+ New Workflow Template</span>
                </button>
            </div>
        </header>

        {{-- Flash Feedback Messages --}}
        @if(session('success'))
            <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800 dark:border-emerald-800/40 dark:bg-emerald-950/40 dark:text-emerald-300 flex items-center justify-between" role="status">
                <div class="flex items-center gap-2">
                    <svg class="w-5 h-5 text-emerald-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                    <span>{{ session('success') }}</span>
                </div>
                <button type="button" @click="$el.parentElement.remove()" class="text-emerald-600 hover:text-emerald-800 text-lg leading-none">&times;</button>
            </div>
        @endif

        @if($errors->any())
            <div class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-800/40 dark:bg-red-950/40 dark:text-red-300 flex items-start justify-between" role="alert">
                <div class="flex items-start gap-2">
                    <svg class="w-5 h-5 text-red-600 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <div>
                        <strong class="font-semibold">Please check the following before continuing:</strong>
                        <ul class="list-disc list-inside mt-1 space-y-0.5 text-xs">
                            @foreach($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                </div>
                <button type="button" @click="$el.parentElement.remove()" class="text-red-600 hover:text-red-800 text-lg leading-none">&times;</button>
            </div>
        @endif

        {{-- Executive Metrics Overview --}}
        <div class="workflow-metrics-grid" aria-label="Workflow overview summary">
            <article class="workflow-metric-card workflow-metric-card--total">
                <div class="workflow-metric-header">
                    <span class="workflow-metric-title">Template Catalog</span>
                    <span class="workflow-metric-icon" aria-hidden="true">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>
                    </span>
                </div>
                <div class="workflow-metric-body">
                    <strong class="workflow-metric-val">{{ $stats['total_templates'] }}</strong>
                    <span class="workflow-metric-tag">Configured</span>
                </div>
                <span class="workflow-metric-footnote">Approval workflows ready for proposals</span>
            </article>

            <article class="workflow-metric-card workflow-metric-card--active">
                <div class="workflow-metric-header">
                    <span class="workflow-metric-title">Active Workflows</span>
                    <span class="workflow-metric-icon" aria-hidden="true">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </span>
                </div>
                <div class="workflow-metric-body">
                    <strong class="workflow-metric-val">{{ $stats['active_versions'] }}</strong>
                    <span class="workflow-metric-tag">Published</span>
                </div>
                <span class="workflow-metric-footnote">Live versions currently used by proposals</span>
            </article>

            <article class="workflow-metric-card workflow-metric-card--draft">
                <div class="workflow-metric-header">
                    <span class="workflow-metric-title">Draft Versions</span>
                    <span class="workflow-metric-icon" aria-hidden="true">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                    </span>
                </div>
                <div class="workflow-metric-body">
                    <strong class="workflow-metric-val">{{ $stats['draft_versions'] }}</strong>
                    <span class="workflow-metric-tag">In Progress</span>
                </div>
                <span class="workflow-metric-footnote">Draft versions being created or updated</span>
            </article>

            <article class="workflow-metric-card workflow-metric-card--runs">
                <div class="workflow-metric-header">
                    <span class="workflow-metric-title">Proposals Processed</span>
                    <span class="workflow-metric-icon" aria-hidden="true">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                    </span>
                </div>
                <div class="workflow-metric-body">
                    <strong class="workflow-metric-val">{{ $stats['total_runs'] }}</strong>
                    <span class="workflow-metric-tag">Total Handled</span>
                </div>
                <span class="workflow-metric-footnote">Student proposals guided through these approval steps</span>
            </article>
        </div>

        {{-- Search and Filter Controls --}}
        <div class="workflow-control-bar">
            <div class="workflow-search-wrap">
                <span class="workflow-search-icon" aria-hidden="true">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                </span>
                <input type="text"
                       x-model="searchQuery"
                       placeholder="Search workflows by name or description..."
                       class="form-input workflow-search-input">
                <button type="button"
                        x-show="searchQuery.length > 0"
                        @click="searchQuery = ''"
                        class="absolute right-2 top-1/2 -translate-y-1/2 text-xs text-slate-400 hover:text-slate-600">
                    &times;
                </button>
            </div>

            <div class="workflow-filter-group" role="tablist" aria-label="Workflow filters">
                <button type="button"
                        @click="statusFilter = 'all'"
                        :class="{ 'is-active': statusFilter === 'all' }"
                        class="workflow-filter-btn">
                    <span>All Workflows</span>
                    <span class="text-2xs opacity-75">({{ $templates->total() }})</span>
                </button>
                <button type="button"
                        @click="statusFilter = 'active'"
                        :class="{ 'is-active': statusFilter === 'active' }"
                        class="workflow-filter-btn">
                    <span>Active</span>
                </button>
                <button type="button"
                        @click="statusFilter = 'has_draft'"
                        :class="{ 'is-active': statusFilter === 'has_draft' }"
                        class="workflow-filter-btn">
                    <span>Has Drafts</span>
                </button>
                <button type="button"
                        @click="statusFilter = 'archived'"
                        :class="{ 'is-active': statusFilter === 'archived' }"
                        class="workflow-filter-btn">
                    <span>Archived</span>
                </button>
            </div>
        </div>

        {{-- Workflow Cards Listing --}}
        <section class="space-y-4" aria-label="Workflows list">
            @forelse($templates as $template)
                @php
                    $templateHasDraft = $template->versions->contains('status', 'draft');
                    $initialVersionId = ($targetVersionId && $template->versions->contains('id', $targetVersionId))
                        ? $targetVersionId
                        : ($template->versions->firstWhere('status', 'published')?->id ?? $template->versions->first()?->id);
                @endphp

                <article class="workflow-card"
                         x-data="{
                             selectedVersion: @js($initialVersionId),
                             editingDraft: false,
                             templateMeta: {
                                 id: @js($template->id),
                                 name: @js($template->name),
                                 description: @js($template->description),
                                 status: @js($template->status),
                                 hasDraft: @js($templateHasDraft)
                             }
                         }"
                         x-show="matchesFilter(templateMeta)"
                         x-transition>

                    {{-- Template Header --}}
                    <div class="workflow-card-header">
                        <div class="workflow-card-title-group">
                            <div class="workflow-card-emblem" aria-hidden="true">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                                </svg>
                            </div>
                            <div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <h2 class="text-base font-bold text-(--color-text-primary)">{{ $template->name }}</h2>
                                    <span class="rounded-full border px-2.5 py-0.5 text-2xs font-bold uppercase tracking-wider {{ $statusBadgeClasses[$template->status] ?? $statusBadgeClasses['active'] }}">
                                        {{ str($template->status)->headline() }}
                                    </span>
                                </div>
                                <p class="mt-1 text-xs text-(--color-text-secondary) max-w-3xl leading-relaxed">
                                    {{ $template->description ?: 'No description provided.' }}
                                </p>
                            </div>
                        </div>

                        <div class="text-xs text-(--color-text-tertiary) flex items-center gap-1.5 self-start pt-1">
                            <span>Created {{ $template->created_at->format('M d, Y') }}</span>
                            @if($template->creator)
                                <span>&middot; by {{ $template->creator->name }}</span>
                            @endif
                        </div>
                    </div>

                    {{-- Version Pills Navigation Bar --}}
                    <div class="workflow-version-pills-bar" role="tablist" aria-label="Version selection">
                        <span class="text-xs font-bold text-(--color-text-tertiary) uppercase tracking-wider mr-1">Workflow Versions:</span>
                        @foreach($template->versions as $version)
                            <button type="button"
                                    @click="selectedVersion = '{{ $version->id }}'; editingDraft = false"
                                    :class="{ 'is-active': selectedVersion === '{{ $version->id }}' }"
                                    class="workflow-version-pill"
                                    role="tab"
                                    :aria-selected="selectedVersion === '{{ $version->id }}'">
                                <span class="workflow-status-dot workflow-status-dot--{{ $version->status }}"></span>
                                <span>Version {{ $version->version_number }}</span>
                                <span class="text-2xs font-normal opacity-80">({{ str($version->status)->headline() }})</span>
                                @if($version->workflow_runs_count > 0)
                                    <span class="px-1.5 py-0.2 rounded-full text-2xs bg-sky-100 text-sky-800 dark:bg-sky-950 dark:text-sky-300 font-bold">
                                        {{ $version->workflow_runs_count }} proposals
                                    </span>
                                @endif
                            </button>
                        @endforeach
                    </div>

                    {{-- Version Content Panes --}}
                    <div class="workflow-version-content">
                        @foreach($template->versions as $version)
                            <div x-show="selectedVersion === '{{ $version->id }}'"
                                 id="version-{{ $version->id }}"
                                 class="{{ $targetVersionId === $version->id ? 'ring-2 ring-(--color-accent) rounded-lg p-2' : '' }}">

                                {{-- Version Summary & Action Banner --}}
                                <div class="workflow-version-banner">
                                    <div class="space-y-1">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <h3 class="text-sm font-bold text-(--color-text-primary)">
                                                Version {{ $version->version_number }}
                                            </h3>
                                            <span class="rounded-full border px-2 py-0.5 text-2xs font-bold uppercase tracking-wider {{ $statusBadgeClasses[$version->status] ?? $statusBadgeClasses['draft'] }}">
                                                {{ str($version->status)->headline() }}
                                            </span>
                                            @if($version->workflow_runs_count > 0)
                                                <span class="rounded-full border border-sky-200 bg-sky-50 px-2 py-0.5 text-2xs font-bold text-sky-700 dark:border-sky-800 dark:bg-sky-950 dark:text-sky-300">
                                                    {{ $version->workflow_runs_count }} Proposals Using This Version
                                                </span>
                                            @endif
                                        </div>

                                        <p class="text-xs text-(--color-text-secondary)">
                                            <strong class="font-medium text-(--color-text-primary)">What changed:</strong>
                                            {{ $version->summary ?: 'Initial workflow setup.' }}
                                        </p>

                                        <div class="flex flex-wrap items-center gap-3 text-2xs text-(--color-text-tertiary) pt-0.5">
                                            @if($version->status === 'published' && $version->published_at)
                                                <span>Published {{ $version->published_at->format('M d, Y g:i A') }} @if($version->publisher)&middot; by {{ $version->publisher->name }}@endif</span>
                                            @elseif($version->status === 'retired' && $version->retired_at)
                                                <span>Retired {{ $version->retired_at->format('M d, Y g:i A') }} @if($version->retirer)&middot; by {{ $version->retirer->name }}@endif</span>
                                            @else
                                                <span>Draft started {{ $version->created_at->format('M d, Y') }}</span>
                                            @endif
                                        </div>
                                    </div>

                                    {{-- Actions Toolbar --}}
                                    <div class="flex flex-wrap items-center gap-2">
                                        @if($version->status === 'draft')
                                            <button type="button"
                                                    @click="editingDraft = !editingDraft"
                                                    class="btn btn-secondary btn-sm">
                                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                                <span x-text="editingDraft ? 'Close Editor' : 'Edit Steps & Rules'"></span>
                                            </button>

                                            <form method="POST"
                                                  action="{{ route('dashboard.proposal-process-template-versions.publish', $version) }}"
                                                  onsubmit="return confirm('Ready to publish this workflow? Once published, it becomes active for incoming event proposals, and its steps will be locked so ongoing reviews stay consistent. You can make future changes by creating a new version draft at any time.');">
                                                @csrf
                                                <button type="submit" class="btn btn-primary btn-sm">
                                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                                    <span>Publish Version</span>
                                                </button>
                                            </form>
                                        @else
                                            <form method="POST" action="{{ route('dashboard.proposal-process-template-versions.draft', $version) }}">
                                                @csrf
                                                <button type="submit" class="btn btn-secondary btn-sm" title="Make a new editable draft based on this version">
                                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7v8a2 2 0 002 2h6M8 7V5a2 2 0 012-2h4.586a1 1 0 01.707.293l4.414 4.414a1 1 0 01.293.707V15a2 2 0 01-2 2h-2M8 7H6a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2v-2"/></svg>
                                                    <span>New Draft from v{{ $version->version_number }}</span>
                                                </button>
                                            </form>
                                        @endif

                                        @if($version->status === 'published')
                                            <form method="POST"
                                                  action="{{ route('dashboard.proposal-process-template-versions.retire', $version) }}"
                                                  onsubmit="return confirm('Retire this workflow? Future event proposals will no longer use it, but existing proposals currently in review will finish normally.');">
                                                @csrf
                                                <button type="submit" class="btn btn-secondary btn-sm text-slate-600 hover:text-slate-900" title="Retire this version">
                                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/></svg>
                                                    <span>Retire</span>
                                                </button>
                                            </form>
                                        @endif
                                    </div>
                                </div>

                                {{-- Inline Draft Editor (When in Draft and editingDraft is toggled) --}}
                                @if($version->status === 'draft')
                                    <div x-show="editingDraft" x-cloak class="mt-4">
                                        <form method="POST" action="{{ route('dashboard.proposal-process-template-versions.update', $version) }}" class="space-y-4">
                                            @csrf
                                            @method('PUT')
                                            @include('dashboard.proposal-process-templates.partials.template-version-form', [
                                                'templateName' => old('template_name', $template->name),
                                                'templateDescription' => old('template_description', $template->description),
                                                'summary' => old('summary', $version->summary),
                                                'steps' => old('steps', $version->steps->map(fn($s) => [
                                                    'display_order' => $s->display_order,
                                                    'step_key' => $s->step_key,
                                                    'title' => $s->title,
                                                    'description' => $s->description,
                                                    'step_type' => $s->step_type,
                                                    'phase' => $s->phase,
                                                    'assigned_role' => $s->condition_config['assigned_role'] ?? '',
                                                    'assigned_scope' => $s->condition_config['assigned_scope'] ?? 'organization',
                                                    'is_required' => $s->is_required,
                                                    'is_gate' => $s->is_gate,
                                                    'prerequisites' => $s->prerequisiteSteps->pluck('step_key')->all(),
                                                ])->all()),
                                                'rules' => old('rules', $version->applicabilityRules->map(fn($r) => [
                                                    'priority' => $r->priority,
                                                    'organizer_role' => $r->organizer_role,
                                                    'organization_type' => $r->organization_type,
                                                    'college_id' => $r->college_id,
                                                    'event_domain' => $r->event_domain,
                                                    'activity_category' => $r->activity_category,
                                                    'location_type' => $r->location_type,
                                                    'status' => $r->status,
                                                    'effective_start' => $r->effective_start?->format('Y-m-d\TH:i'),
                                                    'effective_end' => $r->effective_end?->format('Y-m-d\TH:i'),
                                                    'is_default' => $r->is_default,
                                                ])->all()),
                                                'stepTypes' => $stepTypes,
                                                'organizationTypes' => $organizationTypes,
                                                'colleges' => $colleges,
                                                'submitLabel' => 'Save Changes',
                                                'formId' => 'edit_version_' . $version->id,
                                            ])
                                        </form>
                                    </div>
                                @endif

                                {{-- Read-Only Pipeline & Rules Inspection View --}}
                                <div x-show="!editingDraft" class="space-y-6">
                                    {{-- Process Pipeline Stepper View --}}
                                    <div class="workflow-pipeline-container">
                                        <div class="workflow-pipeline-title-row">
                                            <h4 class="text-xs font-bold uppercase tracking-wider text-(--color-text-tertiary)">
                                                Approval Steps ({{ $version->steps->count() }} in sequence)
                                            </h4>
                                            <span class="text-2xs text-(--color-text-tertiary)">
                                                Each office reviews in sequence. Next reviewer is notified once earlier steps are approved.
                                            </span>
                                        </div>

                                        <div class="workflow-pipeline-flow">
                                            @forelse($version->steps as $step)
                                                <div class="workflow-pipeline-node workflow-pipeline-node--{{ $step->step_type }}">
                                                    <div>
                                                        <div class="workflow-node-top">
                                                            <div class="flex items-center gap-1.5">
                                                                <span class="workflow-node-order">{{ $step->display_order }}</span>
                                                                <span class="workflow-node-type-badge">{{ $stepTypeLabels[$step->step_type] ?? str($step->step_type)->headline() }}</span>
                                                            </div>
                                                            <div class="flex items-center gap-1">
                                                                @if($step->is_gate)
                                                                    <span class="workflow-chip workflow-chip--gate" title="Approval Gate: Must be approved before official permit can be issued">
                                                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                                                                        Gate
                                                                    </span>
                                                                @endif
                                                                @if($step->is_required)
                                                                    <span class="workflow-chip workflow-chip--required">Required</span>
                                                                @endif
                                                            </div>
                                                        </div>

                                                        <div class="mt-2">
                                                            <h5 class="workflow-node-title">{{ $step->title }}</h5>
                                                            <span class="workflow-node-key text-2xs text-slate-500 font-mono">ID: {{ $step->step_key }}</span>
                                                        </div>

                                                        @if($step->description)
                                                            <p class="mt-1.5 text-xs text-(--color-text-secondary) leading-snug">
                                                                {{ $step->description }}
                                                            </p>
                                                        @endif
                                                    </div>

                                                    <div class="space-y-2 pt-2 border-t border-(--color-border)">
                                                        <div class="workflow-node-meta">
                                                            <span class="workflow-chip">
                                                                <svg class="w-3 h-3 text-(--color-text-tertiary)" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                                                                <span>Reviewer: {{ $roleLabels[$step->condition_config['assigned_role'] ?? ''] ?? str($step->condition_config['assigned_role'] ?? 'Any Reviewer')->headline() }}</span>
                                                            </span>
                                                            <span class="workflow-chip">
                                                                <svg class="w-3 h-3 text-(--color-text-tertiary)" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0H5m14 0h2m-2 0h-4m-5 0H3m2 0h4M9 7h6m-6 4h6m-6 4h6"/></svg>
                                                                <span>Scope: {{ $scopeLabels[$step->condition_config['assigned_scope'] ?? ''] ?? str($step->condition_config['assigned_scope'] ?? 'organization')->headline() }}</span>
                                                            </span>
                                                        </div>

                                                        @if($step->prerequisiteSteps->isNotEmpty())
                                                            <div class="workflow-node-prereq">
                                                                <span>Must be signed off first:</span>
                                                                @foreach($step->prerequisiteSteps as $prereq)
                                                                    <span class="workflow-prereq-pill" title="System ID: {{ $prereq->step_key }}">&rarr; {{ $prereq->title ?: str($prereq->step_key)->headline() }}</span>
                                                                @endforeach
                                                            </div>
                                                        @else
                                                            <div class="text-2xs text-(--color-text-tertiary) italic">
                                                                Starts immediately upon proposal submission
                                                            </div>
                                                        @endif
                                                    </div>
                                                </div>
                                            @empty
                                                <div class="p-4 text-center rounded-lg border border-dashed border-(--color-border) text-xs text-(--color-text-secondary)">
                                                    No approval steps added to this version yet.
                                                </div>
                                            @endforelse
                                        </div>
                                    </div>

                                    {{-- Targeting & Applicability Rules --}}
                                    <div>
                                        <h4 class="text-xs font-bold uppercase tracking-wider text-(--color-text-tertiary)">
                                            Who Uses This Workflow ({{ $version->applicabilityRules->count() }} Matching {{ $version->applicabilityRules->count() === 1 ? 'Rule' : 'Rules' }})
                                        </h4>
                                        <div class="workflow-rules-grid">
                                            @forelse($version->applicabilityRules as $rule)
                                                <div class="workflow-rule-card {{ $rule->is_default ? 'is-fallback' : '' }}">
                                                    <div class="workflow-rule-header">
                                                        <span class="workflow-priority-badge">
                                                            {{ $rule->is_default ? 'Default Workflow' : 'Priority Level ' . $rule->priority }}
                                                        </span>
                                                        <span class="text-2xs font-bold uppercase tracking-wider {{ $rule->status === 'active' ? 'text-emerald-700 dark:text-emerald-400' : 'text-slate-500' }}">
                                                            {{ $rule->status === 'active' ? 'Active' : 'Inactive' }}
                                                        </span>
                                                    </div>

                                                    @if($rule->is_default)
                                                        <p class="text-2xs text-(--color-text-secondary) mt-1">
                                                            Automatically applied to any student event proposal that does not match a specialized rule.
                                                        </p>
                                                    @endif

                                                    <div class="flex flex-wrap items-center gap-1.5 mt-1.5">
                                                        @if($rule->organization_type)
                                                            <span class="workflow-chip">Student Group: {{ str($rule->organization_type)->headline() }}</span>
                                                        @endif
                                                        @if($rule->college)
                                                            <span class="workflow-chip">College: {{ $rule->college->code }}</span>
                                                        @endif
                                                        @if($rule->event_domain)
                                                            <span class="workflow-chip">Scope: {{ [
                                                                'student_affairs' => 'Student Affairs & Campus Life',
                                                                'academic_unit' => 'Academic & Departmental',
                                                                'academic_records' => 'Academic Records',
                                                                'hr' => 'Faculty / Staff',
                                                            ][$rule->event_domain] ?? str($rule->event_domain)->headline() }}</span>
                                                        @endif
                                                        @if($rule->location_type)
                                                            <span class="workflow-chip">Venue: {{ [
                                                                'on-campus' => 'On-Campus',
                                                                'off-campus' => 'Off-Campus',
                                                                'online' => 'Online / Virtual',
                                                            ][$rule->location_type] ?? str($rule->location_type)->headline() }}</span>
                                                        @endif
                                                        @if($rule->activity_category)
                                                            <span class="workflow-chip">Activity: {{ str($rule->activity_category)->headline() }}</span>
                                                        @endif
                                                        @if(!$rule->organization_type && !$rule->college && !$rule->event_domain && !$rule->location_type && !$rule->activity_category && !$rule->is_default)
                                                            <span class="workflow-chip">Applies to All Student Event Proposals</span>
                                                        @endif
                                                    </div>

                                                    @if($rule->effective_start || $rule->effective_end)
                                                        <p class="text-2xs text-(--color-text-tertiary) mt-1.5">
                                                            Active dates: {{ $rule->effective_start?->format('M d, Y') ?? 'Immediate' }} &ndash; {{ $rule->effective_end?->format('M d, Y') ?? 'Ongoing' }}
                                                        </p>
                                                    @endif
                                                </div>
                                            @empty
                                                <div class="col-span-2 p-4 text-center rounded-lg border border-dashed border-(--color-border) text-xs text-(--color-text-secondary)">
                                                    No specific matching rules set. This workflow will only be used if manually assigned.
                                                </div>
                                            @endforelse
                                        </div>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </article>
            @empty
                <div class="rounded-xl border border-dashed border-slate-300 dark:border-slate-700 bg-(--color-surface) p-12 text-center">
                    <div class="inline-flex items-center justify-center w-12 h-12 rounded-full bg-emerald-50 dark:bg-emerald-950/50 text-emerald-600 mb-3">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                    </div>
                    <h3 class="text-base font-bold text-(--color-text-primary)">No Workflow Templates Created Yet</h3>
                    <p class="text-sm text-(--color-text-secondary) mt-1 max-w-md mx-auto">
                        Create your first workflow template to set up review steps and permissions for student activity proposals.
                    </p>
                    <button type="button"
                            onclick="document.getElementById('create-template-modal')?.showModal()"
                            class="btn btn-primary mt-4">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                        <span>Create Your First Workflow</span>
                    </button>
                </div>
            @endforelse
        </section>

        @if($templates->hasPages())
            <div class="pt-4 border-t border-(--color-border)">
                {{ $templates->links() }}
            </div>
        @endif
    </div>

    {{-- Create Template Modal Dialog --}}
    <dialog id="create-template-modal"
            class="proposal-modal modal-lg"
            aria-labelledby="create-template-modal-title"
            onclick="if (event.target === this) this.close()">
        <form method="POST"
              action="{{ route('dashboard.proposal-process-templates.store') }}"
              class="proposal-modal-form">
            @csrf
            <div class="proposal-modal-header">
                <div class="proposal-modal-title-row">
                    <span class="proposal-modal-icon" aria-hidden="true">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 4v16m8-8H4"/>
                        </svg>
                    </span>
                    <div>
                        <p class="proposal-modal-kicker">New Proposal Workflow</p>
                        <h2 id="create-template-modal-title" class="proposal-modal-title">Create Template Draft</h2>
                    </div>
                </div>
                <button type="button" onclick="document.getElementById('create-template-modal').close()" class="btn-icon btn-ghost" aria-label="Close">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>

            <div class="proposal-modal-body p-0">
                @include('dashboard.proposal-process-templates.partials.template-version-form', [
                    'templateName' => old('template_name', ''),
                    'templateDescription' => old('template_description', ''),
                    'summary' => old('summary', 'Initial draft version'),
                    'steps' => old('steps', []),
                    'rules' => old('rules', []),
                    'stepTypes' => $stepTypes,
                    'organizationTypes' => $organizationTypes,
                    'colleges' => $colleges,
                    'submitLabel' => 'Create Template Draft',
                    'formId' => 'create_modal',
                ])
            </div>
        </form>
    </dialog>

    {{-- Workflow Guide Modal --}}
    <dialog id="workflow-help-modal"
            class="proposal-modal"
            aria-labelledby="workflow-help-modal-title"
            onclick="if (event.target === this) this.close()">
        <div class="proposal-modal-form">
            <div class="proposal-modal-header">
                <div class="proposal-modal-title-row">
                    <span class="proposal-modal-icon" aria-hidden="true">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </span>
                    <div>
                        <p class="proposal-modal-kicker">Help &amp; Guidance</p>
                        <h2 id="workflow-help-modal-title" class="proposal-modal-title">How Event Approvals Work</h2>
                    </div>
                </div>
                <button type="button" onclick="document.getElementById('workflow-help-modal').close()" class="btn-icon btn-ghost" aria-label="Close">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>

            <div class="proposal-modal-body space-y-4 text-xs text-(--color-text-secondary) leading-relaxed">
                <div class="rounded-lg bg-(--color-surface-raised) p-3.5 border border-(--color-border)">
                    <h4 class="font-bold text-(--color-text-primary) text-sm mb-1">1. Locked Versions Protect Ongoing Events</h4>
                    <p>Once you publish a workflow, it becomes locked so its steps cannot accidentally change mid-review. This guarantees that student proposals already being evaluated stay on track. If you ever need to change the steps, simply click "New Draft from Version" to create an updated version.</p>
                </div>

                <div class="rounded-lg bg-(--color-surface-raised) p-3.5 border border-(--color-border)">
                    <h4 class="font-bold text-(--color-text-primary) text-sm mb-1">2. Clear Step-by-Step Approval Flow</h4>
                    <p>Each office reviews the proposal in order. You can set up dependencies so later reviewers (such as the College Dean or Security Office) are only notified after earlier reviewers (like the faculty adviser) have given their approval.</p>
                </div>

                <div class="rounded-lg bg-(--color-surface-raised) p-3.5 border border-(--color-border)">
                    <h4 class="font-bold text-(--color-text-primary) text-sm mb-1">3. Automatic Matching for Every Event</h4>
                    <p>When a student organization submits an event proposal, the system automatically finds the right workflow based on their group type, college, and event location. If no specialized rule applies, the default workflow is used automatically.</p>
                </div>
            </div>

            <div class="proposal-modal-footer flex justify-end">
                <button type="button" onclick="document.getElementById('workflow-help-modal').close()" class="btn btn-secondary">Got It</button>
            </div>
        </div>
    </dialog>

    {{-- Auto-open create modal if validation errors exist on create submission --}}
    @if($errors->any() && old('template_name') && !old('_method'))
        <script>
            document.addEventListener('DOMContentLoaded', () => {
                document.getElementById('create-template-modal')?.showModal();
            });
        </script>
    @endif
</x-layouts.app>
