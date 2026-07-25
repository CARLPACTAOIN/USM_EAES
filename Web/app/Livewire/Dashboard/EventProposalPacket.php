<?php

namespace App\Livewire\Dashboard;

use App\Models\Event;
use App\Models\EventParticipationScope;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

class EventProposalPacket extends Component
{
    #[Locked]
    public string $eventId;

    #[Locked]
    public bool $isParticipatingWorkspace = false;

    #[Locked]
    public ?string $primaryParticipantScopeId = null;

    public function mount(string $eventId, bool $isParticipatingWorkspace = false, ?string $primaryParticipantScopeId = null): void
    {
        $this->eventId = $eventId;
        $this->isParticipatingWorkspace = $isParticipatingWorkspace;
        $this->primaryParticipantScopeId = $primaryParticipantScopeId;
    }

    #[On('event-proposal-workflow-updated')]
    public function refreshPacket(): void
    {
        // Listener method intentionally empty; Livewire re-renders packet status.
    }

    public function render()
    {
        $event = Event::with(['participationScopes'])->findOrFail($this->eventId);
        $eventScopes = $event->participationScopes;
        $participantScopes = $eventScopes
            ->where('participation_role', EventParticipationScope::ROLE_PARTICIPANT)
            ->values();

        return view('livewire.dashboard.event-proposal-packet', [
            'event' => $event,
            'primaryParticipantScope' => $this->primaryParticipantScopeId
                ? $eventScopes->firstWhere('id', $this->primaryParticipantScopeId)
                : null,
            'approvedScopeCount' => $eventScopes
                ->filter(fn (EventParticipationScope $scope) => $scope->status === EventParticipationScope::STATUS_ACTIVE
                    && (!$scope->ppa_required || in_array($scope->ppa_status, [EventParticipationScope::PPA_APPROVED, EventParticipationScope::PPA_NOT_REQUIRED], true)))
                ->count(),
            'participantScopeCount' => $participantScopes->count(),
        ]);
    }
}
