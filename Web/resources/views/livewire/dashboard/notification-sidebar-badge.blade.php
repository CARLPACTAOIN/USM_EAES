@php
    $label = $count > 99 ? '99+' : (string) $count;
@endphp

<span wire:poll.30s>
    @if($count > 0)
        @if($type === 'needs_action')
            <span class="sidebar-count-badge sidebar-count-warning">{{ $label }}</span>
        @else
            <span class="sidebar-count-badge sidebar-count-unread" aria-label="{{ $count }} unread notifications">{{ $label }}</span>
        @endif
    @endif
</span>
