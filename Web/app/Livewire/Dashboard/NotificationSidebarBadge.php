<?php

namespace App\Livewire\Dashboard;

use App\Services\EventNotificationService;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

class NotificationSidebarBadge extends Component
{
    #[Locked]
    public string $type = 'unread';

    public function mount(string $type = 'unread'): void
    {
        $this->type = in_array($type, ['unread', 'needs_action'], true) ? $type : 'unread';
    }

    #[On('notifications-updated')]
    public function refreshBadge(): void
    {
        // Listener method intentionally empty; Livewire re-renders the badge.
    }

    public function render()
    {
        $summary = auth()->check()
            ? app(EventNotificationService::class)->summaryForUser(auth()->user())
            : ['unread_count' => 0, 'needs_action_count' => 0];

        return view('livewire.dashboard.notification-sidebar-badge', [
            'count' => $this->type === 'needs_action'
                ? (int) ($summary['needs_action_count'] ?? 0)
                : (int) ($summary['unread_count'] ?? 0),
        ]);
    }
}
