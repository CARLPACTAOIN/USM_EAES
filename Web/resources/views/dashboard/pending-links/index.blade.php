<x-layouts.app :title="'Pending QR Links - EAES'">
    <div class="mb-8">
        <h1 class="text-2xl font-display text-(--color-text-primary)">Pending QR Links</h1>
        <p class="text-sm text-(--color-text-secondary) mt-1">Resolve or flag unregistered QR scans from event attendance</p>
    </div>

    @livewire('dashboard.pending-qr-links')
</x-layouts.app>
