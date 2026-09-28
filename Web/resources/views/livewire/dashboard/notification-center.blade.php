<div wire:poll.30s>
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-8">
        <div>
            <h1 class="text-2xl font-display text-(--color-text-primary)">Notifications</h1>
            <p class="text-sm text-(--color-text-secondary) mt-1">Invitations, participant PPA updates, reminders, and review receipts</p>
        </div>
        @if($unreadCount > 0)
            <button type="button"
                    class="btn btn-secondary"
                    wire:click="markAllRead"
                    wire:loading.attr="disabled"
                    wire:target="markAllRead">
                <span wire:loading.remove wire:target="markAllRead">Mark all read</span>
                <span wire:loading wire:target="markAllRead">Updating...</span>
            </button>
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
                    <a wire:key="needs-action-strip-{{ $item['id'] ?? $loop->index }}" href="{{ $item['url'] }}" wire:navigate class="notification-strip-item">
                        <span class="notification-dot notification-dot-warning" aria-hidden="true"></span>
                        <span class="min-w-0">
                            <span class="notification-strip-item-title">{{ $item['label'] }}</span>
                            <span class="notification-strip-item-meta">{{ Str::limit($item['event_title'], 48) }} &middot; {{ $item['participant_name'] }}</span>
                        </span>
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    <div class="card overflow-hidden">
        <div class="card-header flex items-center justify-between gap-3">
            <h2 class="font-semibold text-(--color-text-primary)">Notification Center</h2>
            <span class="badge badge-pending">{{ $unreadCount }} unread</span>
        </div>
        <div class="card-body p-0 divide-y divide-(--color-border)" aria-live="polite" aria-busy="false" wire:loading.attr="aria-busy">
            @forelse($notifications as $notification)
                @php
                    $data = $notification->data;
                    $severity = $data['severity'] ?? 'info';
                    $severity = in_array($severity, ['warning', 'success', 'info', 'error', 'destructive'], true) ? $severity : 'info';
                    $isUnread = is_null($notification->read_at);
                @endphp
                <article class="notification-row {{ $isUnread ? 'notification-row-unread' : '' }}" wire:key="notification-center-row-{{ $notification->id }}">
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
                                &middot; {{ $data['participant_name'] }}
                            @endif
                        </p>
                    </div>
                    <div class="notification-actions">
                        @if(!empty($data['action_url']))
                            <a href="{{ $data['action_url'] }}" wire:navigate class="btn btn-sm btn-primary">{{ $data['action_label'] ?? 'Open' }}</a>
                        @endif
                        @if($isUnread)
                            <button type="button"
                                    class="btn btn-sm btn-secondary"
                                    wire:click="markRead('{{ $notification->id }}')"
                                    wire:loading.attr="disabled"
                                    wire:target="markRead('{{ $notification->id }}')">
                                <span wire:loading.remove wire:target="markRead('{{ $notification->id }}')">Read</span>
                                <span wire:loading wire:target="markRead('{{ $notification->id }}')">Updating...</span>
                            </button>
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
</div>
