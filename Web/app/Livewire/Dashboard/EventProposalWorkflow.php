<?php

namespace App\Livewire\Dashboard;

use App\Models\Event;
use App\Models\EventParticipationScope;
use App\Services\AdminScopeService;
use App\Services\EventNotificationService;
use App\Services\EventPermitService;
use DomainException;
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

    public string $amendmentReason = '';

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

    public function submitProposal(EventPermitService $permits, EventNotificationService $notifications): void
    {
        $this->resetFeedback();

        $event = $this->event();
        $this->authorizeEventAccess($event);

        try {
            $event = $permits->submit($event, auth()->user(), $event->status === Event::STATUS_REVISION_REQUESTED);
        } catch (DomainException $exception) {
            $this->errorNotice = $exception->getMessage();

            return;
        }

        $notifications->notifyPendingInvitationsForEvent(
            $event->fresh(['organization', 'participationScopes.organization', 'participationScopes.delegatedOrganization'])
        );

        $this->notice = 'Proposal submitted for review.';
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

    public function startVenueAmendment(EventPermitService $permits): void
    {
        $this->resetFeedback();
        $this->validate([
            'amendmentReason' => ['required', 'string', 'max:1000'],
        ], [], ['amendmentReason' => 'amendment reason']);

        try {
            $permits->beginVenueAmendment($this->event(), auth()->user(), $this->amendmentReason);
        } catch (DomainException $exception) {
            $this->errorNotice = $exception->getMessage();

            return;
        }

        $this->amendmentReason = '';
        $this->notice = 'Venue amendment started. Scanning is locked until OSA approves the replacement permit.';
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
        return Event::with(['canonicalPpaPacket', 'participationScopes', 'venue', 'permitVersions.approver'])->findOrFail($this->eventId);
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
