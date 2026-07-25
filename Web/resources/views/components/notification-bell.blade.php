@props([
    'summary' => ['unread_count' => 0, 'needs_action_count' => 0],
    'items' => collect(),
    'variant' => 'desktop',
])

@livewire('dashboard.notification-bell', ['variant' => $variant], key('notification-bell-' . $variant))
