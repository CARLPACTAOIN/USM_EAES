<div>
    @if($notice)
        <div class="mb-4 rounded-lg border px-4 py-3 text-sm font-medium" style="background-color: var(--color-success-light); border-color: var(--color-success); color: var(--color-success);" role="status">
            {{ $notice }}
        </div>
    @endif

    @if($errorNotice)
        <div class="mb-4 rounded-lg border px-4 py-3 text-sm font-medium" style="background-color: var(--color-destructive-light); border-color: var(--color-destructive); color: var(--color-destructive);" role="alert">
            {{ $errorNotice }}
        </div>
    @endif

    <div class="card overflow-hidden">
        <div class="p-4 border-b border-(--color-border) bg-(--color-surface-raised)">
            <form wire:submit.prevent="$refresh">
                <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
                    <div class="md:col-span-2">
                        <label for="pending-qr-search" class="sr-only">Search pending QR links</label>
                        <input id="pending-qr-search"
                               type="search"
                               wire:model.live.debounce.400ms="search"
                               class="form-input"
                               placeholder="Search QR value, event, or organization">
                    </div>
                    <div>
                        <label for="pending-qr-source" class="sr-only">Scan source</label>
                        <select id="pending-qr-source" wire:model.live="source" class="form-input form-select">
                            <option value="">All sources</option>
                            <option value="scan">QR scans</option>
                            <option value="manual">Manual IDs</option>
                        </select>
                    </div>
                    <div class="flex items-center gap-2">
                        @if($search !== '' || $source !== '')
                            <button type="button" wire:click="clearFilters" wire:loading.attr="disabled" class="btn btn-secondary btn-sm">Clear</button>
                        @endif
                        <button type="submit" wire:loading.attr="disabled" class="btn btn-primary btn-sm">
                            <span wire:loading.remove wire:target="search,source,clearFilters">Filter</span>
                            <span wire:loading wire:target="search,source,clearFilters">Filtering...</span>
                        </button>
                    </div>
                </div>
                <div class="flex flex-wrap items-center justify-between gap-3 mt-3">
                    <p class="text-xs text-(--color-text-tertiary) font-data">
                        Showing {{ $pendingLinks->firstItem() ?? 0 }}-{{ $pendingLinks->lastItem() ?? 0 }} of {{ $pendingLinks->total() }}
                    </p>
                    <p class="text-xs text-(--color-text-secondary)">
                        Resolution queues attendance processing after the student is linked.
                    </p>
                </div>
            </form>
        </div>

        <div class="overflow-x-auto" wire:loading.delay.class="opacity-60" wire:target="search,source,clearFilters,resolveLink,flagLink">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>QR Code Value</th>
                        <th>Source</th>
                        <th>Event</th>
                        <th>Organization</th>
                        <th>Status</th>
                        <th class="text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($pendingLinks as $link)
                        <tr x-data="{ showResolve: false, showFlag: false }"
                            x-on:pending-qr-link-resolved.window="if ($event.detail.id === '{{ $link->id }}') showResolve = false"
                            x-on:pending-qr-link-flagged.window="if ($event.detail.id === '{{ $link->id }}') showFlag = false"
                            wire:key="pending-qr-row-{{ $link->id }}">
                            <td>
                                <code class="font-data text-sm px-2 py-0.5 rounded" style="background-color: var(--color-surface-overlay);">
                                    {{ Str::limit($link->qr_code_value, 24) }}
                                </code>
                            </td>
                            <td class="text-sm text-(--color-text-secondary)">
                                @if($link->rawScan?->manual_entry)
                                    <span class="badge badge-pending">Manual ID</span>
                                @else
                                    <span class="badge badge-approved">QR Scan</span>
                                @endif
                            </td>
                            <td class="text-sm text-(--color-text-secondary)">{{ $link->event?->title ?? 'Unknown' }}</td>
                            <td class="text-sm text-(--color-text-secondary)">{{ $link->organization?->acronym ?? 'N/A' }}</td>
                            <td><span class="badge badge-{{ $link->status }}">{{ ucfirst($link->status) }}</span></td>
                            <td class="text-right min-w-80">
                                <div class="flex items-center justify-end gap-2">
                                    <button type="button"
                                            @click="showResolve = !showResolve; showFlag = false"
                                            class="btn btn-sm btn-primary"
                                            wire:loading.attr="disabled"
                                            wire:target="resolveLink('{{ $link->id }}')">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                        Resolve
                                    </button>
                                    <button type="button"
                                            @click="showFlag = !showFlag; showResolve = false"
                                            class="btn btn-sm btn-destructive"
                                            wire:loading.attr="disabled"
                                            wire:target="flagLink('{{ $link->id }}')">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 21v-4m0 0V5a2 2 0 012-2h6.5l1 1H21l-3 6 3 6h-8.5l-1-1H5a2 2 0 00-2 2zm9-13.5V9"/></svg>
                                        Flag
                                    </button>
                                </div>

                                <div x-show="showResolve" x-cloak x-transition.opacity.duration.150ms
                                     class="mt-3 p-3 rounded-lg text-left" style="background-color: var(--color-surface-raised); border: 1px solid var(--color-border);">
                                    <form wire:submit.prevent="resolveLink('{{ $link->id }}')">
                                        <div class="mb-2">
                                            <label for="resolve-student-{{ $link->id }}" class="form-label text-xs">Student UUID</label>
                                            <input id="resolve-student-{{ $link->id }}"
                                                   type="text"
                                                   wire:model="resolveStudentIds.{{ $link->id }}"
                                                   class="form-input text-sm font-data @error('resolveStudentIds.' . $link->id) form-input-error @enderror"
                                                   placeholder="Paste the student account UUID"
                                                   required>
                                            @error('resolveStudentIds.' . $link->id) <p class="form-error" role="alert">{{ $message }}</p> @enderror
                                            <p class="form-helper">Use the canonical student account that owns this QR value.</p>
                                        </div>
                                        <div class="mb-3">
                                            <label for="resolve-notes-{{ $link->id }}" class="form-label text-xs">Notes</label>
                                            <input id="resolve-notes-{{ $link->id }}"
                                                   type="text"
                                                   wire:model="resolveNotes.{{ $link->id }}"
                                                   class="form-input text-sm @error('resolveNotes.' . $link->id) form-input-error @enderror"
                                                   placeholder="Optional resolution notes"
                                                   maxlength="1000">
                                            @error('resolveNotes.' . $link->id) <p class="form-error" role="alert">{{ $message }}</p> @enderror
                                        </div>
                                        <div class="flex flex-wrap gap-2">
                                            <button type="submit"
                                                    wire:loading.attr="disabled"
                                                    wire:target="resolveLink('{{ $link->id }}')"
                                                    class="btn btn-sm btn-primary">
                                                <span wire:loading.remove wire:target="resolveLink('{{ $link->id }}')">Confirm Resolve</span>
                                                <span wire:loading wire:target="resolveLink('{{ $link->id }}')">Resolving...</span>
                                            </button>
                                            <button type="button" @click="showResolve = false" class="btn btn-sm btn-secondary">Cancel</button>
                                        </div>
                                    </form>
                                </div>

                                <div x-show="showFlag" x-cloak x-transition.opacity.duration.150ms
                                     class="mt-3 p-3 rounded-lg text-left" style="background-color: var(--color-surface-raised); border: 1px solid var(--color-border);">
                                    <form wire:submit.prevent="flagLink('{{ $link->id }}')">
                                        <div class="mb-3">
                                            <label for="flag-notes-{{ $link->id }}" class="form-label text-xs">Reason</label>
                                            <input id="flag-notes-{{ $link->id }}"
                                                   type="text"
                                                   wire:model="flagNotes.{{ $link->id }}"
                                                   class="form-input text-sm @error('flagNotes.' . $link->id) form-input-error @enderror"
                                                   placeholder="Optional reason"
                                                   maxlength="1000">
                                            @error('flagNotes.' . $link->id) <p class="form-error" role="alert">{{ $message }}</p> @enderror
                                            <p class="form-helper">Flag only when the QR value does not belong to an accredited participant.</p>
                                        </div>
                                        <div class="flex flex-wrap gap-2">
                                            <button type="submit"
                                                    wire:loading.attr="disabled"
                                                    wire:target="flagLink('{{ $link->id }}')"
                                                    class="btn btn-sm btn-destructive">
                                                <span wire:loading.remove wire:target="flagLink('{{ $link->id }}')">Confirm Flag</span>
                                                <span wire:loading wire:target="flagLink('{{ $link->id }}')">Flagging...</span>
                                            </button>
                                            <button type="button" @click="showFlag = false" class="btn btn-sm btn-secondary">Cancel</button>
                                        </div>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6">
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
</div>
