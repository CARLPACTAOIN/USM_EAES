<x-layouts.app :title="'Pending QR Links — EAES'">
    <div class="mb-8">
        <h1 class="text-2xl font-display text-(--color-text-primary)">Pending QR Links</h1>
        <p class="text-sm text-(--color-text-secondary) mt-1">Resolve or flag unregistered QR scans from event attendance</p>
    </div>

    <div class="card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>QR Code Value</th>
                        <th>Event</th>
                        <th>Organization</th>
                        <th>Status</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($pendingLinks as $link)
                    <tr x-data="{ showResolve: false }">
                        <td>
                            <code class="font-data text-sm px-2 py-0.5 rounded" style="background-color: var(--color-surface-overlay);">
                                {{ Str::limit($link->qr_code_value, 24) }}
                            </code>
                        </td>
                        <td class="text-sm text-(--color-text-secondary)">{{ $link->event?->title ?? 'Unknown' }}</td>
                        <td class="text-sm text-(--color-text-secondary)">{{ $link->organization?->acronym ?? 'N/A' }}</td>
                        <td><span class="badge badge-{{ $link->status }}">{{ ucfirst($link->status) }}</span></td>
                        <td class="text-right">
                            <div class="flex items-center justify-end gap-1">
                                <button type="button" @click="showResolve = !showResolve"
                                        class="btn btn-sm btn-primary" title="Resolve">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                    Resolve
                                </button>
                                <form method="POST" action="{{ route('dashboard.pending-links.flag', $link->id) }}" class="inline"
                                      onsubmit="return confirm('Flag this QR link as unaccredited?')">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-destructive" title="Flag">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 21v-4m0 0V5a2 2 0 012-2h6.5l1 1H21l-3 6 3 6h-8.5l-1-1H5a2 2 0 00-2 2zm9-13.5V9"/></svg>
                                        Flag
                                    </button>
                                </form>
                            </div>

                            {{-- Inline Resolve Form --}}
                            <div x-show="showResolve" x-cloak x-transition
                                 class="mt-3 p-3 rounded-lg text-left" style="background-color: var(--color-surface-raised); border: 1px solid var(--color-border);">
                                <form method="POST" action="{{ route('dashboard.pending-links.resolve', $link->id) }}">
                                    @csrf
                                    <div class="mb-2">
                                        <label class="form-label text-xs">Student ID (UUID)</label>
                                        <input type="text" name="student_id" class="form-input text-sm font-data"
                                               placeholder="Paste student UUID" required>
                                        <p class="form-helper">Look up the student in the database and paste their UUID here</p>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label text-xs">Notes</label>
                                        <input type="text" name="notes" class="form-input text-sm"
                                               placeholder="Optional resolution notes" maxlength="1000">
                                    </div>
                                    <div class="flex gap-2">
                                        <button type="submit" class="btn btn-sm btn-primary">Confirm Resolve</button>
                                        <button type="button" @click="showResolve = false" class="btn btn-sm btn-secondary">Cancel</button>
                                    </div>
                                </form>
                            </div>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="5">
                            <div class="empty-state py-8">
                                <svg class="empty-state-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                <p class="empty-state-title">No pending QR links</p>
                                <p class="empty-state-description">All scanned QR codes have been resolved or flagged.</p>
                            </div>
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($pendingLinks->hasPages())
        <div class="card-footer">
            {{ $pendingLinks->links() }}
        </div>
        @endif
    </div>
</x-layouts.app>
