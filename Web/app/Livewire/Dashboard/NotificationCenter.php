<?php

namespace App\Livewire\Dashboard;

use App\Services\EventNotificationService;
use Illuminate\Notifications\DatabaseNotification;
use Livewire\Component;
use Livewire\WithPagination;

class NotificationCenter extends Component
{
    use WithPagination;

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

        return view('livewire.dashboard.notification-center', [
            'notifications' => $user->notifications()->latest()->paginate(20),
            'needsActionItems' => app(EventNotificationService::class)->needsActionItemsForUser($user, 8),
            'unreadCount' => $user->unreadNotifications()->count(),
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
}
