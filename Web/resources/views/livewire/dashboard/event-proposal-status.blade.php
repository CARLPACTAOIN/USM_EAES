<div class="space-y-1">
    <span class="badge badge-{{ str_replace('_', '-', $event->status) }}">
        {{ ucwords(str_replace('_', ' ', $event->status)) }}
    </span>
    <div class="text-xs text-(--color-text-tertiary)">
        Phase: {{ ucwords(str_replace('_', ' ', $event->lifecycle_phase ?? 'scheduled')) }}
    </div>

    @if(!$isParticipatingWorkspace && $user?->can('create-proposals') && $event->status === 'draft')
        <span class="status-nudge status-nudge--warning">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"/></svg>
            Next: Submit proposal
        </span>
    @elseif($user?->hasRole('Super Admin (OSA)') && $event->status === 'submitted')
        <span class="status-nudge status-nudge--review">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12H9m12 0a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            Needs review
        </span>
    @elseif($user?->hasRole('Super Admin (OSA)') && $event->status === 'under_review')
        <span class="status-nudge status-nudge--review">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            Approve or reject
        </span>
    @elseif($isParticipatingWorkspace && $primaryParticipantScope && !$primaryParticipantScope->invitation_acknowledged_at)
        <span class="status-nudge status-nudge--warning">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v3m0 3h.01M12 3a9 9 0 100 18 9 9 0 000-18z"/></svg>
            Acknowledge invite
        </span>
    @endif
</div>
