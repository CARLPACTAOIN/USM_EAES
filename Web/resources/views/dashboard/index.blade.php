<x-layouts.app :title="'Admin Dashboard — EAES'">
    <div class="mb-8">
        <h1 class="text-2xl font-display text-(--color-text-primary)">Dashboard</h1>
        <p class="text-sm text-(--color-text-secondary) mt-1">EAES administration overview</p>
    </div>

    {{-- KPI Stats --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
        <div class="stat-card">
            <div class="flex items-center justify-between mb-2">
                <div class="w-10 h-10 rounded-lg flex items-center justify-center" style="background-color: var(--color-accent-subtle);">
                    <svg class="w-5 h-5" style="color: var(--color-accent);" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                </div>
            </div>
            <p class="stat-value text-(--color-text-primary)">{{ $stats['total_events'] }}</p>
            <p class="stat-label">Total Events</p>
        </div>

        <div class="stat-card">
            <div class="flex items-center justify-between mb-2">
                <div class="w-10 h-10 rounded-lg flex items-center justify-center" style="background-color: var(--color-warning-light);">
                    <svg class="w-5 h-5" style="color: var(--color-warning);" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                </div>
            </div>
            <p class="stat-value text-(--color-warning)">{{ $stats['pending_proposals'] }}</p>
            <p class="stat-label">Pending Proposals</p>
        </div>

        <div class="stat-card">
            <div class="flex items-center justify-between mb-2">
                <div class="w-10 h-10 rounded-lg flex items-center justify-center" style="background-color: var(--color-success-light);">
                    <svg class="w-5 h-5" style="color: var(--color-success);" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                </div>
            </div>
            <p class="stat-value" style="color: var(--color-success);">{{ $stats['approved_events'] }}</p>
            <p class="stat-label">Approved Events</p>
        </div>

        <div class="stat-card">
            <div class="flex items-center justify-between mb-2">
                <div class="w-10 h-10 rounded-lg flex items-center justify-center" style="background-color: var(--color-destructive-light);">
                    <svg class="w-5 h-5" style="color: var(--color-destructive);" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/></svg>
                </div>
            </div>
            <p class="stat-value text-(--color-destructive)">{{ $stats['pending_qr_links'] }}</p>
            <p class="stat-label">Unresolved QR Links</p>
        </div>
    </div>

    {{-- Quick Actions + Recent Events --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {{-- Quick Actions --}}
        <div class="card">
            <div class="card-header">
                <h2 class="font-semibold text-(--color-text-primary)">Quick Actions</h2>
            </div>
            <div class="card-body space-y-2">
                <a href="{{ route('dashboard.events') }}" class="flex items-center gap-3 p-3 rounded-lg hover:bg-(--color-surface-raised) transition-colors cursor-pointer">
                    <div class="w-9 h-9 rounded-lg flex items-center justify-center shrink-0" style="background-color: var(--color-accent-subtle);">
                        <svg class="w-4.5 h-4.5" style="color: var(--color-accent);" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 4v16m8-8H4"/></svg>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-(--color-text-primary)">Create Event Proposal</p>
                        <p class="text-xs text-(--color-text-tertiary)">Submit a new PPA</p>
                    </div>
                </a>
                <a href="{{ route('dashboard.pending-links') }}" class="flex items-center gap-3 p-3 rounded-lg hover:bg-(--color-surface-raised) transition-colors cursor-pointer">
                    <div class="w-9 h-9 rounded-lg flex items-center justify-center shrink-0" style="background-color: var(--color-warning-light);">
                        <svg class="w-4.5 h-4.5" style="color: var(--color-warning);" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101"/></svg>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-(--color-text-primary)">Resolve QR Links</p>
                        <p class="text-xs text-(--color-text-tertiary)">Map unknown scans to students</p>
                    </div>
                </a>
                <a href="{{ route('dashboard.ai') }}" class="flex items-center gap-3 p-3 rounded-lg hover:bg-(--color-surface-raised) transition-colors cursor-pointer">
                    <div class="w-9 h-9 rounded-lg flex items-center justify-center shrink-0" style="background-color: var(--color-info-light);">
                        <svg class="w-4.5 h-4.5" style="color: var(--color-info);" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.75 3.75L8.25 7.5 4.5 9l3.75 1.5 1.5 3.75 1.5-3.75L15 9l-3.75-1.5-1.5-3.75zM17.25 12l-.9 2.25L14.1 15l2.25.75.9 2.25.9-2.25L20.4 15l-2.25-.75-.9-2.25z"/></svg>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-(--color-text-primary)">AI Insights</p>
                        <p class="text-xs text-(--color-text-tertiary)">Review sentiment and scoped queries</p>
                    </div>
                </a>
                <a href="{{ route('dashboard.analytics') }}" class="flex items-center gap-3 p-3 rounded-lg hover:bg-(--color-surface-raised) transition-colors cursor-pointer">
                    <div class="w-9 h-9 rounded-lg flex items-center justify-center shrink-0" style="background: linear-gradient(135deg, rgba(139,92,246,0.15), rgba(99,102,241,0.15));">
                        <svg class="w-4.5 h-4.5 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-(--color-text-primary)">Organization Analytics</p>
                        <p class="text-xs text-(--color-text-tertiary)">Performance overview across all orgs</p>
                    </div>
                </a>
                @if(auth()->user()?->hasRole('Super Admin (OSA)'))
                <a href="{{ route('dashboard.admin-users') }}" class="flex items-center gap-3 p-3 rounded-lg hover:bg-(--color-surface-raised) transition-colors cursor-pointer">
                    <div class="w-9 h-9 rounded-lg flex items-center justify-center shrink-0" style="background-color: var(--color-info-light);">
                        <svg class="w-4.5 h-4.5" style="color: var(--color-info);" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/></svg>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-(--color-text-primary)">Admin Access</p>
                        <p class="text-xs text-(--color-text-tertiary)">Review applications and assignments</p>
                    </div>
                </a>
                @endif
            </div>
        </div>

        {{-- Recent Events --}}
        <div class="card lg:col-span-2">
            <div class="card-header flex items-center justify-between">
                <h2 class="font-semibold text-(--color-text-primary)">Recent Events</h2>
                <a href="{{ route('dashboard.events') }}" class="text-sm text-(--color-accent) hover:underline">View all</a>
            </div>
            <div class="card-body p-0">
                @forelse($recentEvents as $event)
                <div class="flex items-center gap-4 px-6 py-4 border-b border-(--color-border) last:border-b-0">
                    <div class="flex-1 min-w-0">
                        <p class="font-medium text-(--color-text-primary) truncate">{{ $event->title }}</p>
                        <p class="text-xs text-(--color-text-tertiary)">
                            {{ $event->organization?->acronym ?? 'N/A' }} &middot;
                            {{ $event->start_date?->format('M d, Y') }}
                        </p>
                    </div>
                    <span class="badge badge-{{ str_replace('_', '-', $event->status) }}">
                        {{ ucwords(str_replace('_', ' ', $event->status)) }}
                    </span>
                </div>
                @empty
                <div class="empty-state py-8">
                    <svg class="empty-state-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                    <p class="empty-state-title">No events yet</p>
                    <p class="empty-state-description">Create your first event proposal to get started.</p>
                </div>
                @endforelse
            </div>
        </div>
    </div>
</x-layouts.app>
