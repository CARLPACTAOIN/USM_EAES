<x-layouts.app :title="'Notifications — EAES'">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-8">
        <div>
            <h1 class="text-2xl font-display text-(--color-text-primary)">Notifications</h1>
            <p class="text-sm text-(--color-text-secondary) mt-1">Invitations, participant PPA updates, reminders, and review receipts</p>
        </div>
        @if(auth()->user()->unreadNotifications()->exists())
            <form method="POST" action="{{ route('dashboard.notifications.read-all') }}">
                @csrf
                <button type="submit" class="btn btn-secondary">Mark all read</button>
            </form>
        @endif
    </div>

    @if($needsActionItems->isNotEmpty())
        <section class="notification-action-strip mb-6" aria-label="Needs action">
            <div>
                <p class="notification-strip-kicker">Needs Action</p>
                <h2 class="notification-strip-title">{{ $needsActionItems->count() }} item(s) waiting</h2>
            </div>
            <div class="notification-strip-list">
                @foreach($needsActionItems->take(3) as $item)
                    <a href="{{ $item['url'] }}" class="notification-strip-item">
                        <span class="notification-dot notification-dot-warning" aria-hidden="true"></span>
                        <span class="min-w-0">
                            <span class="notification-strip-item-title">{{ $item['label'] }}</span>
                            <span class="notification-strip-item-meta">{{ Str::limit($item['event_title'], 48) }} · {{ $item['participant_name'] }}</span>
                        </span>
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    <div class="card overflow-hidden">
        <div class="card-header flex items-center justify-between gap-3">
            <h2 class="font-semibold text-(--color-text-primary)">Notification Center</h2>
            <span class="badge badge-pending">{{ auth()->user()->unreadNotifications()->count() }} unread</span>
        </div>
        <div class="card-body p-0 divide-y divide-(--color-border)">
            @forelse($notifications as $notification)
                @php
                    $data = $notification->data;
                    $severity = $data['severity'] ?? 'info';
                    $isUnread = is_null($notification->read_at);
                @endphp
                <article class="notification-row {{ $isUnread ? 'notification-row-unread' : '' }}">
                    <span class="notification-dot notification-dot-{{ $severity }}" aria-hidden="true"></span>
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <h3 class="notification-title">{{ $data['title'] ?? 'Notification' }}</h3>
                            @if($isUnread)
                                <span class="badge badge-submitted">Unread</span>
                            @endif
                            @if($data['needs_action'] ?? false)
                                <span class="badge badge-pending">Needs action</span>
                            @endif
                        </div>
                        <p class="notification-body">{{ $data['body'] ?? '' }}</p>
                        <p class="notification-meta">
                            {{ $notification->created_at?->diffForHumans() }}
                            @if(!empty($data['participant_name']))
                                · {{ $data['participant_name'] }}
                            @endif
                        </p>
                    </div>
                    <div class="notification-actions">
                        @if(!empty($data['action_url']))
                            <a href="{{ $data['action_url'] }}" class="btn btn-sm btn-primary">{{ $data['action_label'] ?? 'Open' }}</a>
                        @endif
                        @if($isUnread)
                            <form method="POST" action="{{ route('dashboard.notifications.read', $notification->id) }}">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-secondary">Read</button>
                            </form>
                        @endif
                    </div>
                </article>
            @empty
                <div class="empty-state py-10">
                    <svg class="empty-state-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M14.25 18.75a2.25 2.25 0 01-4.5 0m8.25-5.25V9a6 6 0 10-12 0v4.5L4.5 15.75h15L18 13.5z"/></svg>
                    <p class="empty-state-title">No notifications</p>
                    <p class="empty-state-description">Workflow updates will appear here.</p>
                </div>
            @endforelse
        </div>
        @if($notifications->hasPages())
            <div class="card-footer">{{ $notifications->links() }}</div>
        @endif
    </div>
</x-layouts.app>
