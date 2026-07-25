<?php

namespace App\Livewire\Dashboard;

use App\Services\EventNotificationService;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Component;

class NotificationBell extends Component
{
    #[Locked]
    public string $variant = 'dashboard';

    public function mount(string $variant = 'dashboard'): void
    {
        $this->variant = $variant;
    }

    public function markRead(string $notificationId): void
    {
        $notification = DatabaseNotification::findOrFail($notificationId);
        $this->authorizeNotification($notification);
        $notification->markAsRead();

        $this->dispatch('notifications-updated');
    }

    public function markAllRead(): void
    {
        auth()->user()?->unreadNotifications->markAsRead();
        $this->dispatch('notifications-updated');
    }

    public function render()
    {
        $user = auth()->user();
        $items = $user
            ? $user->notifications()->latest()->limit(8)->get()
            : collect();

        return view('livewire.dashboard.notification-bell', [
            'summary' => $user ? app(EventNotificationService::class)->summaryForUser($user) : [
                'unread_count' => 0,
                'needs_action_count' => 0,
            ],
            'items' => $items,
            'counts' => $this->visibleCounts($items),
        ]);
    }

    private function authorizeNotification(DatabaseNotification $notification): void
    {
        abort_unless(
            $notification->notifiable_type === auth()->user()::class
            && $notification->notifiable_id === auth()->id(),
            403
        );
    }

    private function visibleCounts(Collection $items): array
    {
        return [
            'visible_unread' => $items->filter(fn ($item) => is_null($item->read_at))->count(),
            'needs_action' => $items->filter(fn ($item) => (bool) data_get($item->data, 'needs_action', false))->count(),
            'updates' => $items->filter(fn ($item) => ! (bool) data_get($item->data, 'needs_action', false))->count(),
        ];
    }
}
