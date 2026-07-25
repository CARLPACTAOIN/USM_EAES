<?php

namespace App\Livewire\Dashboard;

use App\Models\Event;
use App\Models\EventParticipationScope;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

class EventProposalStatus extends Component
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
    public function refreshStatus(): void
    {
        // Listener method intentionally empty; Livewire re-renders the status chip.
    }

    public function render()
    {
        return view('livewire.dashboard.event-proposal-status', [
            'event' => Event::findOrFail($this->eventId),
            'primaryParticipantScope' => $this->primaryParticipantScopeId
                ? EventParticipationScope::find($this->primaryParticipantScopeId)
                : null,
            'user' => auth()->user(),
        ]);
    }
}
