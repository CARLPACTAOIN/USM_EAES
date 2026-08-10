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
    @elseif(!$isParticipatingWorkspace && $user?->can('create-proposals') && $event->status === 'revision_requested')
        <span class="status-nudge status-nudge--warning">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5.5H6.75A2.25 2.25 0 004.5 7.75v9.5a2.25 2.25 0 002.25 2.25h9.5a2.25 2.25 0 002.25-2.25V13m-2.25-9.75l3 3m0 0l-9.5 9.5H6.75v-3l9.5-9.5z"/></svg>
            Next: Revise proposal
        </span>
    @elseif($user?->hasRole('Super Admin (OSA)') && $event->status === 'submitted')
        <span class="status-nudge status-nudge--review">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12H9m12 0a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            Needs review
        </span>
    @elseif($user?->hasRole('Super Admin (OSA)') && $event->status === 'under_review')
        <span class="status-nudge status-nudge--review">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0018 0z"/></svg>
            Approve, revise, or reject
        </span>
    @elseif($user?->hasRole('Super Admin (OSA)') && $event->status === 'revision_requested')
        <span class="status-nudge status-nudge--warning">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l2.5 2.5M12 3a9 9 0 100 18 9 9 0 000-18z"/></svg>
            Waiting on revision
        </span>
    @elseif($isParticipatingWorkspace && $primaryParticipantScope && !$primaryParticipantScope->invitation_acknowledged_at)
        <span class="status-nudge status-nudge--warning">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v3m0 3h.01M12 3a9 9 0 100 18 9 9 0 000-18z"/></svg>
            Acknowledge invite
        </span>
    @endif
</div>
