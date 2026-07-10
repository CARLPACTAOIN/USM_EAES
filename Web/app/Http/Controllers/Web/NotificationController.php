<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\EventNotificationService;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

class NotificationController extends Controller
{
    public function index(Request $request, EventNotificationService $notifications)
    {
        $user = $request->user();

        return view('dashboard.notifications.index', [
            'notifications' => $user->notifications()->latest()->paginate(20),
            'needsActionItems' => $notifications->needsActionItemsForUser($user, 8),
        ]);
    }

    public function markRead(Request $request, DatabaseNotification $notification)
    {
        $this->authorizeNotification($request, $notification);
        $notification->markAsRead();

        return redirect()->back()->with('success', 'Notification marked as read.');
    }

    public function readAll(Request $request)
    {
        $request->user()->unreadNotifications->markAsRead();

        return redirect()->back()->with('success', 'All notifications marked as read.');
    }

    private function authorizeNotification(Request $request, DatabaseNotification $notification): void
    {
        abort_unless(
            $notification->notifiable_type === $request->user()::class
            && $notification->notifiable_id === $request->user()->id,
            403
        );
    }
}
