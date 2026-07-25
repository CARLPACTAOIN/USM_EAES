@php
    $items = collect($items);
    $unreadTotal = (int) ($summary['unread_count'] ?? 0);
    $visibleUnreadCount = (int) ($counts['visible_unread'] ?? 0);
    $needsActionCount = (int) ($counts['needs_action'] ?? 0);
    $updatesCount = (int) ($counts['updates'] ?? 0);
    $badgeLabel = $unreadTotal > 99 ? '99+' : (string) $unreadTotal;
    $panelId = 'notification-bell-panel-' . $variant;
    $triggerLabel = $unreadTotal > 0
        ? "Open notification menu, {$unreadTotal} unread"
        : 'Open notification menu';
@endphp

<div class="notification-bell notification-bell--{{ $variant }} {{ $unreadTotal > 0 ? 'has-unread' : '' }}"
     x-data="{ open: false, filter: 'all' }"
     wire:poll.30s
     @keydown.escape.window="open = false"
     @click.outside="open = false">
    <button type="button"
            class="notification-bell-trigger"
            @click="open = ! open"
            :aria-expanded="open.toString()"
            aria-haspopup="dialog"
            aria-controls="{{ $panelId }}"
            aria-label="{{ $triggerLabel }}">
        <svg class="notification-bell-trigger-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M14.25 18.75a2.25 2.25 0 01-4.5 0m8.25-5.25V9a6 6 0 10-12 0v4.5L4.5 15.75h15L18 13.5z"/>
        </svg>
        @if($unreadTotal > 0)
            <span class="notification-bell-badge" aria-hidden="true">{{ $badgeLabel }}</span>
        @endif
    </button>

    <section id="{{ $panelId }}"
             class="notification-bell-panel"
             x-show="open"
             x-transition.origin.top.right
             x-cloak
             role="dialog"
             aria-label="Notifications">
        <div class="notification-bell-header">
            <div>
                <p class="notification-bell-kicker">Notifications</p>
                <h2 class="notification-bell-title">Workflow inbox</h2>
            </div>
            @if($unreadTotal > 0)
                <button type="button"
                        class="notification-bell-mark-all"
                        wire:click="markAllRead"
                        wire:loading.attr="disabled"
                        wire:target="markAllRead">
                    <span wire:loading.remove wire:target="markAllRead">Mark all read</span>
                    <span wire:loading wire:target="markAllRead">Updating...</span>
                </button>
            @endif
        </div>

        <div class="notification-bell-filters" role="tablist" aria-label="Notification filters">
            <button type="button"
                    class="notification-bell-filter"
                    :class="{ 'is-active': filter === 'all' }"
                    @click="filter = 'all'">
                All <span>{{ $items->count() }}</span>
            </button>
            <button type="button"
                    class="notification-bell-filter"
                    :class="{ 'is-active': filter === 'needs_action' }"
                    @click="filter = 'needs_action'">
                Needs action <span>{{ $needsActionCount }}</span>
            </button>
            <button type="button"
                    class="notification-bell-filter"
                    :class="{ 'is-active': filter === 'unread' }"
                    @click="filter = 'unread'">
                Unread <span>{{ $visibleUnreadCount }}</span>
            </button>
            <button type="button"
                    class="notification-bell-filter"
                    :class="{ 'is-active': filter === 'updates' }"
                    @click="filter = 'updates'">
                Updates <span>{{ $updatesCount }}</span>
            </button>
        </div>

        <div class="notification-bell-list" aria-live="polite" aria-busy="false" wire:loading.attr="aria-busy">
            @foreach($items as $notification)
                @php
                    $data = $notification->data ?? [];
                    $isUnread = is_null($notification->read_at);
                    $needsAction = (bool) ($data['needs_action'] ?? false);
                    $severity = $data['severity'] ?? 'info';
                    $severity = in_array($severity, ['warning', 'success', 'info', 'error', 'destructive'], true) ? $severity : 'info';
                    $filters = [];

                    if ($needsAction) {
                        $filters[] = 'needs_action';
                    } else {
                        $filters[] = 'updates';
                    }

                    if ($isUnread) {
                        $filters[] = 'unread';
                    }
                @endphp
                <article class="notification-bell-item {{ $isUnread ? 'is-unread' : '' }}"
                         wire:key="notification-bell-item-{{ $notification->id }}"
                         data-filters="{{ implode(' ', $filters) }}"
                         x-show="filter === 'all' || $el.dataset.filters.split(' ').includes(filter)"
                         x-transition.opacity>
                    <span class="notification-bell-icon notification-bell-icon--{{ $severity }}" aria-hidden="true">
                        @switch($severity)
                            @case('warning')
                                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
                                @break
                            @case('success')
                                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                @break
                            @case('error')
                            @case('destructive')
                                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 9v3.75m0 3h.01M4.93 19.07a10 10 0 1114.14 0 10 10 0 01-14.14 0z"/></svg>
                                @break
                            @default
                                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M13 16h-1v-4h-1m1-4h.01M12 21a9 9 0 100-18 9 9 0 000 18z"/></svg>
                        @endswitch
                    </span>
                    <div class="notification-bell-item-main">
                        <div class="notification-bell-item-head">
                            <h3>{{ $data['title'] ?? 'Notification' }}</h3>
                            <time datetime="{{ $notification->created_at?->toIso8601String() }}">{{ $notification->created_at?->diffForHumans() }}</time>
                        </div>
                        <p>{{ Str::limit($data['body'] ?? '', 112) }}</p>
                        <div class="notification-bell-item-meta">
                            @if($isUnread)
                                <span class="notification-bell-chip">Unread</span>
                            @endif
                            @if($needsAction)
                                <span class="notification-bell-chip notification-bell-chip--warning">Needs action</span>
                            @endif
                            @if(!empty($data['participant_name']))
                                <span>{{ $data['participant_name'] }}</span>
                            @endif
                        </div>
                        <div class="notification-bell-actions">
                            @if(!empty($data['action_url']))
                                <a href="{{ $data['action_url'] }}" wire:navigate class="notification-bell-action-primary">{{ $data['action_label'] ?? 'Open' }}</a>
                            @endif
                            @if($isUnread)
                                <button type="button"
                                        class="notification-bell-action-secondary"
                                        wire:click="markRead('{{ $notification->id }}')"
                                        wire:loading.attr="disabled"
                                        wire:target="markRead('{{ $notification->id }}')">
                                    <span wire:loading.remove wire:target="markRead('{{ $notification->id }}')">Read</span>
                                    <span wire:loading wire:target="markRead('{{ $notification->id }}')">...</span>
                                </button>
                            @endif
                        </div>
                    </div>
                </article>
            @endforeach

            @if($items->isEmpty())
                <div class="notification-bell-empty" x-show="filter === 'all'">
                    <span>No notifications</span>
                    <p>Workflow updates will appear here.</p>
                </div>
            @endif

            @if($needsActionCount === 0)
                <div class="notification-bell-empty" x-show="filter === 'needs_action'" x-cloak>
                    <span>No action needed</span>
                    <p>Participant handoffs and reviews are clear.</p>
                </div>
            @endif

            @if($visibleUnreadCount === 0)
                <div class="notification-bell-empty" x-show="filter === 'unread'" x-cloak>
                    <span>No unread notifications</span>
                    <p>You are caught up on recent workflow updates.</p>
                </div>
            @endif

            @if($updatesCount === 0)
                <div class="notification-bell-empty" x-show="filter === 'updates'" x-cloak>
                    <span>No updates</span>
                    <p>Receipts and informational notices will appear here.</p>
                </div>
            @endif
        </div>
    </section>
</div>
