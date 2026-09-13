<x-layouts.app>
    <div class="space-y-6">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div><p class="text-xs font-semibold uppercase text-(--color-text-tertiary)">Immutable permit record</p><h1 class="text-2xl font-semibold">{{ $event->title }}</h1><p class="text-sm text-(--color-text-secondary)">{{ $event->organization?->name }} · Current permit v{{ $event->permit_version }}</p></div>
            <a href="{{ route('dashboard.events') }}" class="btn btn-secondary">Back to proposals</a>
        </div>
        @if($event->pending_venue_amendment_reason)
            <div class="proposal-error-summary" role="status"><p>Amendment pending: {{ $event->pending_venue_amendment_reason }}</p></div>
        @endif
        <div class="grid gap-4 lg:grid-cols-2">
            @forelse($event->permitVersions as $version)
                <article class="card p-5">
                    <div class="flex items-start justify-between gap-3"><div><h2 class="text-lg font-semibold">Permit v{{ $version->version_number }}</h2><p class="text-xs text-(--color-text-tertiary)">Approved {{ $version->approved_at?->format('M d, Y g:i A') }} by {{ $version->approver?->name }}</p></div><span class="badge {{ $version->superseded_at ? 'badge-rejected' : 'badge-approved' }}">{{ $version->superseded_at ? 'Superseded' : 'Current' }}</span></div>
                    <dl class="mt-4 grid gap-3 text-sm"><div><dt class="text-(--color-text-tertiary)">Venue</dt><dd>{{ $version->venue_name_snapshot }} @if($version->venue_location_snapshot) — {{ $version->venue_location_snapshot }} @endif</dd></div><div><dt class="text-(--color-text-tertiary)">Confirmation</dt><dd>{{ $version->confirmation_office }} · {{ $version->confirmation_date?->format('M d, Y') }} @if($version->confirmation_reference_number) · Ref {{ $version->confirmation_reference_number }} @endif</dd></div>@if($version->amendment_reason)<div><dt class="text-(--color-text-tertiary)">Amendment reason</dt><dd>{{ $version->amendment_reason }}</dd></div>@endif</dl>
                    <div class="mt-4 flex flex-wrap gap-2"><a class="btn btn-sm btn-secondary" href="{{ route('dashboard.events.permit-history.evidence', [$event, $version]) }}">Confirmation evidence</a><a class="btn btn-sm btn-secondary" href="{{ route('dashboard.events.permit-history.ppa', [$event, $version]) }}">Official PPA</a></div>
                </article>
            @empty
                <div class="card p-6 text-(--color-text-tertiary)">No approved permit version exists yet.</div>
            @endforelse
        </div>
    </div>
</x-layouts.app>
