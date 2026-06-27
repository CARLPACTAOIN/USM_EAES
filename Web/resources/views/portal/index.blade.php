<x-layouts.app :title="'Student Portal — EAES'">
    {{-- Page Header --}}
    <div class="mb-8">
        <h1 class="text-2xl font-display text-(--color-text-primary)">Welcome, {{ $user->name }}</h1>
        <p class="text-sm text-(--color-text-secondary) mt-1">Your student portal overview</p>
    </div>

    {{-- Profile Completion Banner --}}
    @unless($profileComplete)
    <div class="card mb-6" style="border-left: 4px solid var(--color-warning);">
        <div class="card-body flex flex-col sm:flex-row items-start sm:items-center gap-4">
            <div class="w-10 h-10 rounded-lg flex items-center justify-center shrink-0" style="background-color: var(--color-warning-light);">
                <svg class="w-5 h-5" style="color: var(--color-warning);" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>
            </div>
            <div class="flex-1">
                <h3 class="font-semibold text-(--color-text-primary)">Complete Your Profile</h3>
                <p class="text-sm text-(--color-text-secondary) mt-0.5">Register your college, organization, student ID, and physical QR code to start tracking attendance.</p>
            </div>
            <a href="{{ route('portal.profile') }}" class="btn btn-primary shrink-0">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                Complete Profile
            </a>
        </div>
    </div>
    @endunless

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {{-- Pending Evaluations --}}
        <div class="card">
            <div class="card-header flex items-center justify-between">
                <h2 class="font-semibold text-(--color-text-primary)">Pending Evaluations</h2>
                <span class="badge badge-pending">{{ $pendingEvalEvents->count() }}</span>
            </div>
            <div class="card-body p-0">
                @forelse($pendingEvalEvents as $event)
                <a href="{{ route('portal.evaluation', $event->id) }}"
                   class="flex items-center gap-4 px-6 py-4 hover:bg-(--color-surface-raised) transition-colors cursor-pointer border-b border-(--color-border) last:border-b-0">
                    <div class="w-10 h-10 rounded-lg flex items-center justify-center shrink-0" style="background-color: var(--color-accent-subtle);">
                        <svg class="w-5 h-5" style="color: var(--color-accent);" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/></svg>
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="font-medium text-(--color-text-primary) truncate">{{ $event->title }}</p>
                        <p class="text-xs text-(--color-text-tertiary)">{{ $event->start_date->format('M d, Y') }}</p>
                    </div>
                    <svg class="w-5 h-5 text-(--color-text-tertiary) shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                </a>
                @empty
                <div class="empty-state py-8">
                    <svg class="empty-state-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <p class="empty-state-title">All caught up!</p>
                    <p class="empty-state-description">No pending evaluations at this time.</p>
                </div>
                @endforelse
            </div>
        </div>

        {{-- Recent Attendance --}}
        <div class="card">
            <div class="card-header">
                <h2 class="font-semibold text-(--color-text-primary)">Recent Attendance</h2>
            </div>
            <div class="card-body p-0">
                @forelse($recentAttendance as $record)
                <div class="flex items-center gap-4 px-6 py-4 border-b border-(--color-border) last:border-b-0">
                    <div class="w-10 h-10 rounded-lg flex items-center justify-center shrink-0
                        {{ $record->valid ? 'bg-green-50' : 'bg-amber-50' }}">
                        @if($record->valid)
                        <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        @else
                        <svg class="w-5 h-5 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        @endif
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="font-medium text-(--color-text-primary) truncate">{{ $record->event->title ?? 'Unknown Event' }}</p>
                        <p class="text-xs text-(--color-text-tertiary)">
                            {{ $record->time_in ? \Carbon\Carbon::parse($record->time_in)->format('M d, g:i A') : 'No time-in' }}
                        </p>
                    </div>
                    <span class="badge {{ $record->valid ? 'badge-approved' : 'badge-pending' }}">
                        {{ $record->valid ? 'Valid' : 'Pending' }}
                    </span>
                </div>
                @empty
                <div class="empty-state py-8">
                    <svg class="empty-state-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                    <p class="empty-state-title">No attendance records</p>
                    <p class="empty-state-description">Attend events and scan your QR code to see your history here.</p>
                </div>
                @endforelse
            </div>
        </div>
    </div>
</x-layouts.app>
