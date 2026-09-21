<div class="space-y-6">
    <section class="card p-5">
        <div class="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
            <div>
                <p class="text-xs font-semibold uppercase text-(--color-text-tertiary)">System-recorded usage</p>
                <h1 class="text-2xl font-semibold text-(--color-text-primary)">Venue Availability</h1>
                <p class="mt-1 max-w-3xl text-sm text-(--color-text-secondary)">
                    Compare catalog venues and manual campus venue names against EAES proposals for the selected date and time.
                    {{ $availability['disclaimer'] }}
                </p>
            </div>

            <a href="{{ route('dashboard.events') }}"
               class="btn btn-secondary">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 7l-4 5 4 5M5 12h14"/></svg>
                Event Proposals
            </a>
        </div>
    </section>

    <section class="card p-5">
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-6">
            <div>
                <label class="form-label" for="availability-date">Date</label>
                <input id="availability-date" type="date" class="form-input" wire:model.live="date" required>
            </div>
            <div>
                <label class="form-label" for="availability-start">Start time</label>
                <input id="availability-start" type="time" class="form-input" wire:model.live="startTime">
            </div>
            <div>
                <label class="form-label" for="availability-end">End time</label>
                <input id="availability-end" type="time" class="form-input" wire:model.live="endTime">
            </div>
            <div>
                <label class="form-label" for="availability-status">Status</label>
                <select id="availability-status" class="form-input form-select" wire:model.live="status">
                    <option value="all">All statuses</option>
                    <option value="used">Used</option>
                    <option value="tentative">Tentative</option>
                    <option value="available">Available</option>
                </select>
            </div>
            <div>
                <label class="form-label" for="availability-source">Venue source</label>
                <select id="availability-source" class="form-input form-select" wire:model.live="source">
                    <option value="all">Catalog and manual</option>
                    <option value="catalog">Catalog only</option>
                    <option value="manual">Manual only</option>
                </select>
            </div>
            <div>
                <label class="form-label" for="availability-search">Search</label>
                <input id="availability-search" type="search" class="form-input" placeholder="Venue or office" wire:model.live.debounce.300ms="search">
            </div>
        </div>
        <div class="mt-4 flex justify-end">
            <button type="button" class="btn btn-sm btn-ghost" wire:click="clearFilters">Clear filters</button>
        </div>
    </section>

    <section class="grid gap-4 md:grid-cols-3">
        <div class="card p-5">
            <p class="text-xs font-semibold uppercase text-(--color-text-tertiary)">Used</p>
            <p class="mt-2 text-3xl font-semibold text-(--color-text-primary)">{{ $availability['summary']['used'] }}</p>
            <p class="mt-1 text-sm text-(--color-text-secondary)">Approved or scheduled usage, including one-hour buffers.</p>
        </div>
        <div class="card p-5">
            <p class="text-xs font-semibold uppercase text-(--color-text-tertiary)">Tentative</p>
            <p class="mt-2 text-3xl font-semibold text-(--color-text-primary)">{{ $availability['summary']['tentative'] }}</p>
            <p class="mt-1 text-sm text-(--color-text-secondary)">Submitted or under-review proposals.</p>
        </div>
        <div class="card p-5">
            <p class="text-xs font-semibold uppercase text-(--color-text-tertiary)">Available</p>
            <p class="mt-2 text-3xl font-semibold text-(--color-text-primary)">{{ $availability['summary']['available'] }}</p>
            <p class="mt-1 text-sm text-(--color-text-secondary)">Active catalog venues without matching usage.</p>
        </div>
    </section>

    <section class="card overflow-hidden">
        <div class="table-wrapper">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Venue</th>
                        <th>Status</th>
                        <th>Recorded usage</th>
                        <th>Managing office</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($availability['venues'] as $venue)
                        <tr wire:key="availability-{{ $venue['source'] }}-{{ md5($venue['normalized_name']) }}">
                            <td>
                                <p class="font-medium text-(--color-text-primary)">{{ $venue['display_name'] }}</p>
                                <p class="text-xs text-(--color-text-tertiary)">
                                    {{ $venue['source'] === 'catalog' ? 'Catalog venue' : 'Manual venue name' }}
                                    @if($venue['campus_location'])
                                        · {{ $venue['campus_location'] }}
                                    @endif
                                    @if($venue['college'])
                                        · {{ $venue['college'] }}
                                    @endif
                                </p>
                            </td>
                            <td>
                                @php($badgeClass = match ($venue['status']) {
                                    'used' => 'badge-approved',
                                    'tentative' => 'badge-pending',
                                    default => 'badge-draft',
                                })
                                <span class="badge {{ $badgeClass }}">{{ Str::headline($venue['status']) }}</span>
                                @if($venue['source'] === 'catalog' && ! $venue['is_active'])
                                    <p class="mt-1 text-xs text-(--color-text-tertiary)">Retired catalog venue</p>
                                @endif
                            </td>
                            <td>
                                @if($venue['events'] === [])
                                    <p class="text-sm text-(--color-text-secondary)">No system-recorded venue usage found for this date/time.</p>
                                @else
                                    <div class="space-y-2">
                                        @foreach($venue['events'] as $event)
                                            <div class="rounded-md border border-(--color-border) bg-(--color-surface-raised) p-3">
                                                <p class="font-medium text-(--color-text-primary)">{{ $event['title'] }}</p>
                                                <p class="text-xs text-(--color-text-secondary)">
                                                    {{ $event['organization'] ?? 'Organizer unavailable' }}
                                                    · {{ Str::headline($event['status']) }}
                                                    · {{ $event['date'] }} {{ $event['start_time'] }}-{{ $event['end_time'] }}
                                                </p>
                                                @if($event['usage_status'] === 'used')
                                                    <p class="mt-1 text-xs text-(--color-text-tertiary)">Buffered window: {{ $event['buffer_start_time'] }}-{{ $event['buffer_end_time'] }}</p>
                                                @endif
                                            </div>
                                        @endforeach
                                    </div>
                                @endif
                            </td>
                            <td>
                                <p class="text-sm text-(--color-text-primary)">{{ $venue['managing_office'] ?: 'Not recorded' }}</p>
                                <p class="mt-1 text-xs text-(--color-text-tertiary)">{{ $venue['inquiry_instructions'] ?: $availability['disclaimer'] }}</p>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="text-center text-(--color-text-tertiary)">
                                No system-recorded venue usage found for this date/time. Confirm reservation with the managing office.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>
