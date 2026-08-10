<div class="ppa-status-grid">
    @if($isParticipatingWorkspace && $primaryParticipantScope)
        <span class="badge badge-{{ str_replace('_', '-', $primaryParticipantScope->ppa_status) }}">
            {{ ucwords(str_replace('_', ' ', $primaryParticipantScope->ppa_status)) }}
        </span>

        <span class="ppa-status-item {{ $primaryParticipantScope->invitation_acknowledged_at ? 'ppa-status-item--ok' : 'ppa-status-item--warn' }}">
            @if($primaryParticipantScope->invitation_acknowledged_at)
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                Acknowledged
            @else
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v3m0 3h.01M12 3a9 9 0 100 18 9 9 0 000-18z"/></svg>
                Needs acknowledgement
            @endif
        </span>

        <hr class="ppa-status-divider">

        <div class="ppa-status-group">
            <span class="ppa-status-item {{ $primaryParticipantScope->ppa_softcopy_path ? 'ppa-status-item--ok' : 'ppa-status-item--warn' }}">
                @if($primaryParticipantScope->ppa_softcopy_path)
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                @else
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01"/></svg>
                @endif
                Softcopy
            </span>
            <span class="ppa-status-item {{ $primaryParticipantScope->hardcopy_submitted ? 'ppa-status-item--ok' : 'ppa-status-item--warn' }}">
                @if($primaryParticipantScope->hardcopy_submitted)
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                @else
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01"/></svg>
                @endif
                Hardcopy
            </span>
        </div>

        <span class="ppa-status-item ppa-status-item--muted">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
            {{ $approvedScopeCount > 0 ? 'Scanner ready' : 'Scanner locked' }}
        </span>
    @else
        <div class="ppa-status-group">
            @if($event->proposal_document_path)
                <a href="{{ route('dashboard.events.proposal-document', $event->id) }}" class="ppa-status-item ppa-status-item--ok hover:underline">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                    Softcopy
                </a>
            @else
                <span class="ppa-status-item ppa-status-item--error">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    Softcopy
                </span>
            @endif
            <span class="ppa-status-item {{ $event->hardcopy_submitted ? 'ppa-status-item--ok' : 'ppa-status-item--warn' }}">
                @if($event->hardcopy_submitted)
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                @else
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01"/></svg>
                @endif
                Hardcopy
            </span>
        </div>

        <hr class="ppa-status-divider">

        <div class="ppa-status-group">
            <span class="ppa-status-item {{ $event->head_organization_signed ? 'ppa-status-item--ok' : 'ppa-status-item--warn' }}">
                @if($event->head_organization_signed)
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                @else
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01"/></svg>
                @endif
                Head
            </span>
            <span class="ppa-status-item {{ $event->adviser_signed ? 'ppa-status-item--ok' : 'ppa-status-item--warn' }}">
                @if($event->adviser_signed)
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                @else
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01"/></svg>
                @endif
                Adviser
            </span>
        </div>

        <div class="ppa-status-group" style="margin-top: 0.125rem;">
            <span class="ppa-scope-badge">{{ $approvedScopeCount }} scope{{ $approvedScopeCount !== 1 ? 's' : '' }}</span>
            <span class="ppa-scope-badge">{{ $participantScopeCount }} participant{{ $participantScopeCount !== 1 ? 's' : '' }}</span>
        </div>
    @endif
</div>
