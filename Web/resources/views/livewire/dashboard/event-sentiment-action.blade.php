<span class="inline-flex flex-col {{ $fullWidth ? 'w-full items-stretch' : 'items-end' }} gap-1">
    <button type="button"
            class="btn btn-sm btn-secondary {{ $fullWidth ? 'w-full justify-center min-h-9' : '' }}"
            title="Queue sentiment analysis"
            wire:click="analyze"
            wire:loading.attr="disabled"
            wire:target="analyze"
            @disabled($queued || $pendingComments === 0)>
        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 3.75L8.25 7.5 4.5 9l3.75 1.5 1.5 3.75 1.5-3.75L15 9l-3.75-1.5-1.5-3.75z"/></svg>
        <span wire:loading.remove wire:target="analyze">{{ $queued ? 'Queued' : $buttonLabel }}</span>
        <span wire:loading wire:target="analyze">Queueing...</span>
    </button>

    @if($notice || $errorNotice)
        <span class="{{ $fullWidth ? 'text-left' : 'max-w-40 text-right' }} text-[0.68rem] font-medium {{ $errorNotice ? 'text-(--color-destructive)' : 'text-(--color-success)' }}">
            {{ $errorNotice ?: $notice }}
        </span>
    @endif
</span>
