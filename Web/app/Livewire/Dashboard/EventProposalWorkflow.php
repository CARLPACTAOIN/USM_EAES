<?php

namespace App\Livewire\Dashboard;

use App\Models\Event;
use App\Models\EventParticipationScope;
use App\Services\AdminScopeService;
use App\Services\EventNotificationService;
use App\Services\ParticipationScopeService;
use App\Support\EventEvaluationForm;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

class EventProposalWorkflow extends Component
{
    #[Locked]
    public string $eventId;

    #[Locked]
    public bool $isParticipatingWorkspace = false;

    #[Locked]
    public ?string $primaryParticipantScopeId = null;

    public ?string $notice = null;

    public ?string $errorNotice = null;

    public string $reviewRemarks = '';

    public function mount(string $eventId, bool $isParticipatingWorkspace = false, ?string $primaryParticipantScopeId = null): void
    {
        $this->eventId = $eventId;
        $this->isParticipatingWorkspace = $isParticipatingWorkspace;
        $this->primaryParticipantScopeId = $primaryParticipantScopeId;
    }

    #[On('event-proposal-workflow-updated')]
    public function refreshWorkflow(): void
    {
        // Listener method intentionally empty; Livewire re-renders the action set.
    }

    public function markHardcopy(ParticipationScopeService $participationScopes): void
    {
        $this->resetFeedback();

        abort_unless(auth()->user()?->can('create-proposals'), 403);

        $event = $this->event();
        $this->authorizeEventAccess($event);
        $this->authorizeOrganizerMutation($event);

        if (! in_array($event->status, [Event::STATUS_DRAFT, Event::STATUS_SUBMITTED, Event::STATUS_UNDER_REVIEW, Event::STATUS_REVISION_REQUESTED], true)) {
            $this->errorNotice = 'Hardcopy tracking can only be updated before approval or rejection.';

            return;
        }

        $event->update([
            'hardcopy_submitted' => true,
            'hardcopy_submitted_at' => now(),
            'head_organization_signed' => true,
            'adviser_signed' => true,
        ]);

        $participationScopes->ensureOrganizerScope($event->fresh())->update([
            'hardcopy_submitted' => true,
            'org_head_signed' => true,
            'adviser_signed' => true,
        ]);

        $this->notice = 'Hardcopy status recorded.';
        $this->dispatchWorkflowUpdated();
    }

    public function submitProposal(ParticipationScopeService $participationScopes, EventNotificationService $notifications): void
    {
        $this->resetFeedback();

        abort_unless(auth()->user()?->can('create-proposals'), 403);

        $event = $this->event();
        $this->authorizeEventAccess($event);
        $this->authorizeOrganizerMutation($event);

        if (! in_array($event->status, [Event::STATUS_DRAFT, Event::STATUS_REVISION_REQUESTED], true)) {
            $this->errorNotice = 'Only draft or revision-requested proposals can be submitted.';

            return;
        }

        if (! $event->proposal_document_path) {
            $this->errorNotice = 'Attach the official PPA proposal softcopy before submitting for review.';

            return;
        }

        if (! $event->hardcopy_submitted) {
            $this->errorNotice = 'Confirm hardcopy submission to OSA before submitting for review.';

            return;
        }

        $event->update(['status' => Event::STATUS_SUBMITTED]);
        $participationScopes->ensureOrganizerScope($event)->update([
            'ppa_status' => EventParticipationScope::PPA_SUBMITTED,
            'submitted_by' => auth()->id(),
            'submitted_at' => now(),
            'review_remarks' => null,
        ]);

        $notifications->notifyPendingInvitationsForEvent(
            $event->fresh(['organization', 'participationScopes.organization', 'participationScopes.delegatedOrganization'])
        );

        $this->notice = 'Proposal submitted for review.';
        $this->dispatchWorkflowUpdated();
    }

    public function startReview(ParticipationScopeService $participationScopes): void
    {
        $this->resetFeedback();
        $this->authorizeOsa();

        $event = $this->event();

        if ($event->status !== Event::STATUS_SUBMITTED) {
            $this->errorNotice = 'Only submitted proposals can be reviewed.';

            return;
        }

        $event->update(['status' => Event::STATUS_UNDER_REVIEW]);
        $participationScopes->ensureOrganizerScope($event)->update([
            'ppa_status' => EventParticipationScope::PPA_UNDER_REVIEW,
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
        ]);

        $this->notice = 'Proposal is now under review.';
        $this->dispatchWorkflowUpdated();
    }

    public function approveProposal(ParticipationScopeService $participationScopes): void
    {
        $this->resetFeedback();
        $this->authorizeOsa();

        $event = $this->event();

        if (! in_array($event->status, [Event::STATUS_SUBMITTED, Event::STATUS_UNDER_REVIEW], true)) {
            $this->errorNotice = 'Proposal must be submitted or under review to approve.';

            return;
        }

        if (! $event->proposal_document_path || ! $event->hardcopy_submitted) {
            $this->errorNotice = 'Proposal softcopy and hardcopy submission must be recorded before approval.';

            return;
        }

        if (! EventEvaluationForm::hasLockedForm($event)) {
            $this->errorNotice = 'A locked evaluation form with at least one question is required before approval.';

            return;
        }

        $event->update(['status' => Event::STATUS_APPROVED]);
        $participationScopes->ensureOrganizerScope($event->fresh())->update([
            'ppa_status' => EventParticipationScope::PPA_APPROVED,
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
            'approved_at' => now(),
            'review_remarks' => null,
        ]);

        $this->notice = 'Event proposal approved. Scanning is now unlocked.';
        $this->dispatchWorkflowUpdated();
    }

    public function rejectProposal(ParticipationScopeService $participationScopes): void
    {
        $this->resetFeedback();
        $this->authorizeOsa();

        $event = $this->event();

        if (! in_array($event->status, [Event::STATUS_SUBMITTED, Event::STATUS_UNDER_REVIEW], true)) {
            $this->errorNotice = 'Proposal must be submitted or under review to reject.';

            return;
        }

        $event->update(['status' => Event::STATUS_REJECTED]);
        $participationScopes->ensureOrganizerScope($event)->update([
            'ppa_status' => EventParticipationScope::PPA_REJECTED,
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
        ]);

        $this->notice = 'Event proposal rejected.';
        $this->dispatchWorkflowUpdated();
    }

    public function requestRevision(ParticipationScopeService $participationScopes, EventNotificationService $notifications): void
    {
        $this->resetFeedback();
        $this->authorizeOsa();

        $this->validate([
            'reviewRemarks' => ['required', 'string', 'max:1000'],
        ], [], [
            'reviewRemarks' => 'revision note',
        ]);

        $event = $this->event();

        if (! in_array($event->status, [Event::STATUS_SUBMITTED, Event::STATUS_UNDER_REVIEW], true)) {
            $this->errorNotice = 'Proposal must be submitted or under review to request revision.';

            return;
        }

        $event->update(['status' => Event::STATUS_REVISION_REQUESTED]);
        $organizerScope = $participationScopes->ensureOrganizerScope($event);
        $organizerScope->update([
            'ppa_status' => EventParticipationScope::PPA_REVISION_REQUESTED,
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
            'review_remarks' => trim($this->reviewRemarks),
        ]);

        $notifications->notifyProposalRevisionRequested(
            $organizerScope->fresh(['event.organization', 'organization', 'delegatedOrganization'])
        );

        $this->reviewRemarks = '';
        $this->notice = 'Event proposal returned for revision.';
        $this->dispatchWorkflowUpdated();
    }

    public function acknowledgeParticipantInvitation(EventNotificationService $notifications): void
    {
        $this->resetFeedback();

        if (! $this->primaryParticipantScopeId) {
            $this->errorNotice = 'No participant invitation is selected.';

            return;
        }

        $event = $this->event();
        $participationScope = EventParticipationScope::findOrFail($this->primaryParticipantScopeId);
        abort_unless($participationScope->event_id === $event->id, 404);

        if (! $notifications->acknowledgeInvitation($participationScope, auth()->user())) {
            abort(403, 'Only the invited participant organization can acknowledge this invitation.');
        }

        $this->notice = 'Participant invitation acknowledged.';
        $this->dispatchWorkflowUpdated();
    }

    public function render()
    {
        return view('livewire.dashboard.event-proposal-workflow', [
            'event' => $this->eventForDisplay(),
            'primaryParticipantScope' => $this->primaryParticipantScopeId
                ? EventParticipationScope::find($this->primaryParticipantScopeId)
                : null,
            'user' => auth()->user(),
        ]);
    }

    private function event(): Event
    {
        return Event::findOrFail($this->eventId);
    }

    private function eventForDisplay(): Event
    {
        return Event::with(['participationScopes'])->findOrFail($this->eventId);
    }

    private function authorizeOsa(): void
    {
        abort_unless(auth()->user()?->hasRole('Super Admin (OSA)'), 403);
    }

    private function authorizeEventAccess(Event $event): void
    {
        $scope = app(AdminScopeService::class)->forUser(auth()->user());

        if (! $scope) {
            abort(403, 'Access denied. No active admin assignment found.');
        }

        if ($scope->isSuperAdmin()) {
            if ($event->status === Event::STATUS_DRAFT) {
                abort(403, 'Draft proposals are visible only to their organizers until submitted.');
            }

            return;
        }

        if ($scope->canAccessEvent($event)) {
            return;
        }

        abort(403, $scope->isLsgAdmin()
            ? 'Access denied. LSG Admin restricted to college boundary.'
            : 'Access denied. Restricted to your organization boundary.');
    }

    private function authorizeOrganizerMutation(Event $event): void
    {
        $scope = app(AdminScopeService::class)->forUser(auth()->user());

        abort_unless(
            $scope && ! $scope->isSuperAdmin() && $scope->organizationId === $event->organization_id,
            403,
            'Only the event organizer can update or submit this proposal.'
        );
    }

    private function dispatchWorkflowUpdated(): void
    {
        $this->dispatch('event-proposal-workflow-updated');
        $this->dispatch('notifications-updated');
    }

    private function resetFeedback(): void
    {
        $this->notice = null;
        $this->errorNotice = null;
        $this->resetErrorBag();
    }
}
