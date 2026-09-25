<div>
    @if($event->pending_venue_amendment_reason)
        @php($previousPermit = $event->permitVersions->first())
        <div class="card p-4 mb-4">
            <p class="text-xs font-semibold uppercase text-(--color-text-tertiary)">Venue amendment comparison</p>
            <div class="grid gap-3 mt-2 sm:grid-cols-2">
                <div><p class="form-label">Previous approved venue</p><p>{{ $previousPermit?->venue_name_snapshot ?? 'Unavailable' }} @if($previousPermit?->venue_location_snapshot) — {{ $previousPermit->venue_location_snapshot }} @endif</p></div>
                <div><p class="form-label">Proposed venue</p><p>{{ $event->venue?->displayName() ?? $event->location_details ?? 'Not yet supplied' }}</p></div>
            </div>
            <p class="form-helper">Reason: {{ $event->pending_venue_amendment_reason }}</p>
        </div>
    @endif
<div class="event-primary-cta" aria-live="polite">
    @if(!$isParticipatingWorkspace && $event->canonicalPpaPacket)
        <a class="btn btn-sm btn-secondary" href="{{ route('dashboard.events.proposal-preview', $event) }}" target="_blank" rel="noopener">Proposal preview</a>
        <a class="btn btn-sm btn-secondary" href="{{ route('dashboard.events.permit-preview', $event) }}" target="_blank" rel="noopener">Permit preview</a>
    @endif

    @if(!$isParticipatingWorkspace && $event->requiresVenueConfirmation())
        <span class="event-workflow-feedback {{ $event->venue_confirmation_document_path ? 'event-workflow-feedback--success' : 'event-workflow-feedback--error' }}">
            {{ $event->location_type === 'off-campus' ? ($event->venue_confirmation_document_path ? 'Venue confirmed' : 'Venue confirmation incomplete') : 'Packet venue branch active' }}
            @if($event->permit_version > 0) · Permit v{{ $event->permit_version }} @endif
        </span>
        @if($event->venue_confirmation_document_path)
            <a class="btn btn-sm btn-secondary" href="{{ route('dashboard.events.venue-evidence', $event) }}">Evidence</a>
        @endif
        @if($event->permit_version > 0)
            <a class="btn btn-sm btn-secondary" href="{{ route('dashboard.events.permit-history', $event) }}">Permit history</a>
        @endif
    @endif
    @if($notice)
        <span class="event-workflow-feedback event-workflow-feedback--success"
              role="status"
              aria-atomic="true"
              x-data="{ visible: true }"
              x-show="visible"
              x-init="setTimeout(() => visible = false, 4000)"
              x-transition.opacity.duration.150ms>{{ $notice }}</span>
    @endif

    @if($errorNotice)
        <span class="event-workflow-feedback event-workflow-feedback--error">{{ $errorNotice }}</span>
    @endif

    @if(!$isParticipatingWorkspace && $user?->can('create-proposals') && in_array($event->status, ['draft', 'revision_requested'], true))
        @if(!$event->hardcopy_submitted)
            <button type="button"
                    class="btn btn-sm btn-secondary"
                    title="Record hardcopy submission"
                    wire:click="markHardcopy"
                    wire:loading.attr="disabled"
                    wire:target="markHardcopy,submitProposal">
                <span wire:loading.remove wire:target="markHardcopy">Hardcopy</span>
                <span wire:loading wire:target="markHardcopy">Saving...</span>
            </button>
        @endif

        <button type="button"
                class="btn btn-sm btn-primary"
                title="Submit for Review"
                wire:click="submitProposal"
                wire:loading.attr="disabled"
                wire:target="markHardcopy,submitProposal">
            <span wire:loading.remove wire:target="submitProposal">Submit</span>
            <span wire:loading wire:target="submitProposal">Submitting...</span>
        </button>
    @endif

    @if($user?->hasRole('Super Admin (OSA)'))
        @if($event->status === 'submitted')
            <button type="button"
                    class="btn btn-sm btn-secondary"
                    title="Start Review"
                    wire:click="startReview"
                    wire:loading.attr="disabled"
                    wire:target="startReview,approveProposal,requestRevision,rejectProposal">
                <span wire:loading.remove wire:target="startReview">Review</span>
                <span wire:loading wire:target="startReview">Reviewing...</span>
            </button>
        @endif

        @if(in_array($event->status, ['submitted', 'under_review'], true))
            <button type="button"
                    class="btn btn-sm"
                    style="background-color: var(--color-success); color: white;"
                    title="Approve"
                    wire:click="approveProposal"
                    wire:loading.attr="disabled"
                    wire:target="startReview,approveProposal,requestRevision,rejectProposal">
                <span wire:loading.remove wire:target="approveProposal">Approve</span>
                <span wire:loading wire:target="approveProposal">Approving...</span>
            </button>
            <form wire:submit="requestRevision" class="event-revision-inline">
                <label for="event-revision-note-{{ $event->id }}" class="sr-only">Revision note</label>
                <input type="text"
                       id="event-revision-note-{{ $event->id }}"
                       class="form-input text-xs @error('reviewRemarks') form-input-error @enderror"
                       placeholder="Revision note"
                       maxlength="1000"
                       wire:model="reviewRemarks">
                @error('reviewRemarks')
                    <span class="form-error" role="alert">{{ $message }}</span>
                @enderror
                <button type="submit"
                        class="btn btn-sm btn-secondary"
                        title="Request Revision"
                        wire:loading.attr="disabled"
                        wire:target="requestRevision">
                    <span wire:loading.remove wire:target="requestRevision">Request Revision</span>
                    <span wire:loading wire:target="requestRevision">Returning...</span>
                </button>
            </form>
            <button type="button"
                    class="btn btn-sm btn-destructive"
                    title="Reject"
                    wire:click="rejectProposal"
                    wire:loading.attr="disabled"
                    wire:target="startReview,approveProposal,requestRevision,rejectProposal">
                <span wire:loading.remove wire:target="rejectProposal">Reject</span>
                <span wire:loading wire:target="rejectProposal">Rejecting...</span>
            </button>
        @endif
    @endif

    @if(!$user?->hasRole('Super Admin (OSA)') && $isParticipatingWorkspace && $primaryParticipantScope && !$primaryParticipantScope->invitation_acknowledged_at)
        <button type="button"
                class="btn btn-sm btn-primary"
                title="Acknowledge participant invitation"
                wire:click="acknowledgeParticipantInvitation"
                wire:loading.attr="disabled"
                wire:target="acknowledgeParticipantInvitation">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 12.75l2.25 2.25L15.75 9M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <span wire:loading.remove wire:target="acknowledgeParticipantInvitation">Acknowledge</span>
            <span wire:loading wire:target="acknowledgeParticipantInvitation">Saving...</span>
        </button>
    @endif

    @if(!$isParticipatingWorkspace && in_array($event->status, ['approved', 'completed'], true))
        <a href="{{ route('dashboard.events.analytics', $event) }}"
           wire:navigate
           class="btn btn-sm btn-secondary"
           title="View Event Analytics"
           style="background-color: var(--color-accent-subtle); color: var(--color-accent); border: 1px solid var(--color-accent-light);">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
            Analytics
        </a>
    @endif

    @if(!$isParticipatingWorkspace && !$user?->hasRole('Super Admin (OSA)') && $event->status === 'approved' && ($event->lifecycle_phase ?? 'scheduled') === 'scheduled')
        <form wire:submit="startVenueAmendment" class="event-revision-inline">
            <label for="venue-amendment-reason-{{ $event->id }}" class="sr-only">Venue amendment reason</label>
            <input id="venue-amendment-reason-{{ $event->id }}" type="text" class="form-input text-xs @error('amendmentReason') form-input-error @enderror"
                   placeholder="Why must the venue change?" maxlength="1000" wire:model="amendmentReason">
            @error('amendmentReason') <span class="form-error" role="alert">{{ $message }}</span> @enderror
            <button type="submit" class="btn btn-sm btn-secondary" wire:loading.attr="disabled" wire:target="startVenueAmendment">
                <span wire:loading.remove wire:target="startVenueAmendment">Start venue amendment</span>
                <span wire:loading wire:target="startVenueAmendment">Starting…</span>
            </button>
        </form>
    @endif
</div>
</div>
