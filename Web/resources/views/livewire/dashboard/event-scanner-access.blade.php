<div>
    <div class="proposal-modal-body space-y-4">
        <section class="proposal-modal-section">
            <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h3 class="font-semibold text-sm text-(--color-text-primary)">Assigned Scanners</h3>
                    <p class="text-xs text-(--color-text-tertiary)">Only active assignments can activate scanner mode in the mobile app.</p>
                </div>
                <div wire:loading class="rounded-full border border-(--color-border) px-3 py-1 text-xs font-semibold text-(--color-text-tertiary)">
                    Updating...
                </div>
            </div>

            @if($notice)
                <div class="mt-3 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm font-medium text-emerald-800" role="status">
                    {{ $notice }}
                </div>
            @endif

            @if($errorNotice)
                <div class="mt-3 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm font-medium text-red-700" role="alert">
                    {{ $errorNotice }}
                </div>
            @endif

            @if($canAssignScanners)
                <form wire:submit="assignScanners" class="mt-3 grid items-start gap-3 xl:grid-cols-[minmax(13rem,0.95fr)_minmax(18rem,1.25fr)_minmax(13rem,0.9fr)_auto]">
                    <div>
                        <label for="scanner-scope-{{ $event->id }}" class="sr-only">Attendance scope</label>
                        <select
                            id="scanner-scope-{{ $event->id }}"
                            class="form-input form-select text-sm"
                            required
                            wire:model.live="scopeId"
                            wire:change="setScope($event.target.value)"
                        >
                            <option value="">Attendance scope</option>
                            @foreach($scannerScopes as $scope)
                                <option value="{{ $scope->id }}">{{ $scope->displayName() }} · {{ ucwords(str_replace('_', ' ', $scope->capture_mode)) }}</option>
                            @endforeach
                        </select>
                        @error('scopeId') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div class="relative">
                        <label for="scanner-search-{{ $event->id }}" class="sr-only">Search eligible scanner</label>
                        <input
                            id="scanner-search-{{ $event->id }}"
                            type="search"
                            class="form-input text-sm"
                            placeholder="Search and add scanners"
                            autocomplete="off"
                            wire:model.live.debounce.300ms="scannerQuery"
                            @disabled(!$scopeId)
                        >
                        @if($scopeId && $eligibleScannerUsers->isNotEmpty())
                            <div class="absolute z-30 mt-1 max-h-56 w-full overflow-auto rounded-lg border border-(--color-border) bg-(--color-surface) shadow-lg">
                                @foreach($eligibleScannerUsers as $scanner)
                                    <button
                                        type="button"
                                        class="block w-full px-3 py-2 text-left text-sm hover:bg-(--color-surface-muted) focus:bg-(--color-surface-muted) focus:outline-none"
                                        wire:click="addScanner('{{ $scanner->id }}')"
                                        wire:loading.attr="disabled"
                                    >
                                        <span class="block font-medium text-(--color-text-primary)">{{ $scanner->name }}</span>
                                        <span class="block text-xs text-(--color-text-tertiary)">
                                            {{ $scanner->student_id_number ?: $scanner->employeeRosterEntry?->employee_id ?: $scanner->email }}
                                            · {{ $scanner->email }}
                                            · {{ $scanner->organization?->acronym ?: $scanner->organization?->name ?: $scanner->activeOrganizationMemberships->map(fn ($membership) => $membership->organization?->acronym ?: $membership->organization?->name)->filter()->unique()->join(', ') ?: 'No scope' }}
                                        </span>
                                    </button>
                                @endforeach
                            </div>
                        @elseif($scopeId && strlen(trim($scannerQuery)) > 0)
                            <p class="mt-1 text-xs text-(--color-text-tertiary)">No eligible scanner matches your search.</p>
                        @endif
                        <p wire:loading wire:target="scannerQuery" class="mt-1 text-xs text-(--color-text-tertiary)">Searching eligible scanners...</p>
                        @error('scannerQuery') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        @error('selectedScannerIds') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <input type="text" wire:model="label" class="form-input text-sm" placeholder="Main Gate, Gym Entrance">

                    <button type="submit" class="btn btn-primary btn-sm self-start whitespace-nowrap" wire:loading.attr="disabled" wire:target="assignScanners">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                        <span wire:loading.remove wire:target="assignScanners">{{ count($selectedScannerIds) > 1 ? 'Assign ' . count($selectedScannerIds) : 'Assign' }}</span>
                        <span wire:loading wire:target="assignScanners">Assigning...</span>
                    </button>

                    @if($selectedScanners->isNotEmpty())
                        <div class="rounded-lg border border-(--color-border) bg-(--color-surface-muted) p-3 xl:col-span-4">
                            <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                <div>
                                    <p class="text-xs font-semibold uppercase tracking-wide text-(--color-text-tertiary)">Selected scanners</p>
                                    <p class="text-sm font-medium text-(--color-text-primary)">{{ $selectedScanners->count() }} {{ $selectedScanners->count() === 1 ? 'account ready to assign' : 'accounts ready to assign' }}</p>
                                </div>
                                <button type="button" class="text-xs font-medium text-(--color-text-tertiary) hover:text-red-600 focus:outline-none" wire:click="clearSelected">
                                    Clear selection
                                </button>
                            </div>
                            <div class="mt-3 grid max-h-36 gap-2 overflow-auto pr-1 sm:grid-cols-2 xl:grid-cols-3">
                                @foreach($selectedScanners as $scanner)
                                    <div class="flex min-w-0 items-center justify-between gap-3 rounded-md border border-(--color-border) bg-(--color-surface) px-3 py-2" wire:key="selected-scanner-{{ $scanner->id }}">
                                        <div class="min-w-0">
                                            <p class="truncate text-sm font-medium text-(--color-text-primary)">{{ $scanner->name }}</p>
                                            <p class="truncate text-xs text-(--color-text-tertiary)">{{ $scanner->student_id_number ?: $scanner->email }} · {{ $scanner->organization?->acronym ?: $scanner->organization?->name ?: 'No scope' }}</p>
                                        </div>
                                        <button type="button" class="grid h-7 w-7 shrink-0 place-items-center rounded-full text-(--color-text-tertiary) hover:bg-red-50 hover:text-red-600 focus:outline-none focus:ring-2 focus:ring-red-200" wire:click="removeScanner('{{ $scanner->id }}')" aria-label="Remove {{ $scanner->name }}">
                                            <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                        </button>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </form>

                @if($scannerScopes->isEmpty())
                    <p class="mt-2 text-xs text-(--color-warning)">No approved attendance scope is available for scanner assignment.</p>
                @endif
            @endif

            <div class="mt-4 space-y-3">
                @forelse($event->scannerAssignments as $assignment)
                    <div class="rounded-lg border border-(--color-border) bg-(--color-surface-raised) p-3" wire:key="assignment-{{ $assignment->id }}">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <p class="text-sm font-semibold text-(--color-text-primary)">{{ $assignment->scannerUser?->name ?? 'Unknown scanner' }}</p>
                                <p class="text-xs text-(--color-text-tertiary)">
                                    {{ $assignment->label ?: 'Event scanner' }} · {{ $assignment->scannerUser?->email }}
                                </p>
                                <div class="mt-2 grid gap-1 text-xs text-(--color-text-secondary)">
                                    <span>Scope: {{ $assignment->participationScope?->displayName() ?? 'N/A' }}</span>
                                    <span>Assigned by: {{ $assignment->assignedBy?->name ?? 'Unknown admin' }}</span>
                                    <span>Last activated: {{ $assignment->last_activated_at?->diffForHumans() ?? 'Never' }}</span>
                                    <span>Expires: {{ $assignment->expires_at?->format('M d, Y g:i A') ?? 'Not set' }}</span>
                                </div>
                            </div>
                            <div class="text-left sm:text-right">
                                <span class="badge badge-{{ str_replace('_', '-', $assignment->status) }}">{{ ucfirst($assignment->status) }}</span>
                                <div class="mt-2 text-xs text-(--color-text-tertiary)">
                                    {{ $assignment->scannerSessions->filter(fn ($session) => $session->status === 'active' && (!$session->expires_at || $session->expires_at->isFuture()))->count() }} active session(s)
                                </div>
                                @if($assignment->status === 'active' && $canAssignScanners)
                                    <button type="button" class="btn btn-sm btn-destructive mt-3" wire:click="revokeAssignment('{{ $assignment->id }}')" wire:loading.attr="disabled" wire:target="revokeAssignment">
                                        <span wire:loading.remove wire:target="revokeAssignment">Revoke assignment</span>
                                        <span wire:loading wire:target="revokeAssignment">Revoking...</span>
                                    </button>
                                @elseif($assignment->status !== 'active')
                                    <p class="mt-2 text-xs text-(--color-text-tertiary)">New activation is blocked for this assignment.</p>
                                @endif
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="rounded-lg border border-dashed border-(--color-border) bg-(--color-surface-muted) p-4">
                        <p class="text-sm font-medium text-(--color-text-primary)">No assigned scanners yet</p>
                        <p class="mt-1 text-xs text-(--color-text-tertiary)">Assign a completed institutional student profile to let them log in and activate this event in the mobile scanner app.</p>
                    </div>
                @endforelse
            </div>
        </section>

        <section class="proposal-modal-section">
            <h3 class="font-semibold text-sm text-(--color-text-primary) mb-3">Active Devices / Session History</h3>
            <div class="space-y-3">
                @forelse($event->scannerSessions as $session)
                    <div class="rounded-lg border border-(--color-border) bg-(--color-surface-raised) p-3" wire:key="session-{{ $session->id }}">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <p class="text-sm font-semibold text-(--color-text-primary)">{{ $session->label ?: 'Scanner Session' }}</p>
                                <p class="text-xs text-(--color-text-tertiary)">
                                    {{ $session->scannerUser?->name ?? 'Unassigned scanner' }}
                                    @if($session->assignment_id)
                                        · Assignment-backed
                                    @endif
                                </p>
                                <div class="mt-2 grid gap-1 text-xs text-(--color-text-secondary)">
                                    <span>Scope: {{ $session->participationScope?->displayName() ?? 'N/A' }}</span>
                                    <span>Device: {{ $session->device_id ? 'Bound' : 'Not activated' }}</span>
                                    <span>Last seen: {{ $session->last_seen_at?->diffForHumans() ?? 'Never' }}</span>
                                    <span>Expires: {{ $session->expires_at?->format('M d, Y g:i A') ?? 'Not set' }}</span>
                                </div>
                            </div>
                            <div class="text-left sm:text-right">
                                <span class="badge badge-{{ str_replace('_', '-', $session->status) }}">{{ ucfirst($session->status) }}</span>
                                <div class="mt-2 text-xs text-(--color-text-tertiary)">
                                    {{ $session->pending_scans_count }} pending · {{ $session->synced_scans_count }} synced · {{ $session->failed_scans_count }} failed
                                </div>
                                @if($session->status === 'active' && $canAssignScanners)
                                    <button type="button" class="btn btn-sm btn-destructive mt-3" wire:click="revokeSession('{{ $session->id }}')" wire:loading.attr="disabled" wire:target="revokeSession">
                                        <span wire:loading.remove wire:target="revokeSession">Revoke</span>
                                        <span wire:loading wire:target="revokeSession">Revoking...</span>
                                    </button>
                                @endif
                            </div>
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-(--color-text-tertiary)">No active device sessions yet. Assign scanners first; their sessions will appear after they log in and activate this event.</p>
                @endforelse
            </div>
        </section>
    </div>
</div>
