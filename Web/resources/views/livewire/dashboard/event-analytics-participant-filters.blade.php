@php
    $label = fn ($value) => \Illuminate\Support\Str::of($value ?: 'N/A')->replace('_', ' ')->replace('-', ' ')->title();
    $tone = function (?string $value): string {
        return match ($value) {
            'active', 'approved', 'present_on_time', 'valid', 'submitted', 'positive', 'healthy', 'completed' => 'good',
            'late', 'late_cutoff', 'left_early', 'pending', 'neutral', 'mixed', 'evaluation_open', 'processing', 'scheduled' => 'warn',
            'expired', 'revoked', 'absent', 'invalid', 'missing', 'negative', 'needs_review', 'evaluation_closed' => 'danger',
            default => 'neutral',
        };
    };
    $toneClass = fn (?string $value) => 'analytics-tone-'.$tone($value);
    $selectedScope = collect($scopeOptions)->firstWhere('id', $scopeId);
    $selectedDay = collect($dayOptions)->firstWhere('id', $dayId);
@endphp

<div class="analytics-card">
    <div class="analytics-card-header analytics-card-header--filters">
        <div class="analytics-card-heading-stack">
            <div>
                <h2 class="analytics-card-title">Participant Drilldown</h2>
                <p class="analytics-card-kicker">{{ $participantRows->total() }} row(s)</p>
            </div>
            @if($activeFilterCount > 0)
                <span class="analytics-chip analytics-tone-good"><span class="analytics-chip-dot"></span>{{ $activeFilterCount }} active</span>
            @endif
        </div>
        <div class="analytics-filter-toolbar">
            <label for="event-participant-per-page" class="analytics-filter-label">Rows</label>
            <select id="event-participant-per-page"
                    name="per_page"
                    form="event-participant-filter-form"
                    wire:model.live="perPage"
                    class="form-select analytics-filter-control analytics-filter-control--rows">
                @foreach([10, 25, 50, 100] as $count)
                    <option value="{{ $count }}" @selected((int) $perPage === $count)>{{ $count }} / page</option>
                @endforeach
            </select>
            <span wire:loading.flex
                  wire:target="applyFilters,clearFilters,perPage,nextPage,previousPage,gotoPage"
                  class="items-center gap-1 text-[11px] font-semibold text-(--color-accent)">
                <svg class="h-3 w-3 animate-spin" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                </svg>
                Updating
            </span>
        </div>
    </div>

    <div class="analytics-card-body border-b border-(--color-border)">
        <form id="event-participant-filter-form"
              method="GET"
              action="{{ $filterActionUrl }}"
              wire:submit.prevent="applyFilters"
              class="space-y-3">
            <input type="hidden" name="tab" value="attendance">

            <div class="analytics-filter-titlebar">
                <div class="analytics-filter-title">
                    <svg class="h-4 w-4 text-(--color-accent)" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 4h18M6 12h12M10 20h4"/>
                    </svg>
                    <span>Attendance Filters</span>
                </div>
            </div>

            <div class="analytics-filter-grid">
                <div class="analytics-filter-field analytics-filter-field--search">
                    <label class="analytics-filter-label" for="event-participant-search">Participant</label>
                    <div class="analytics-filter-search">
                        <svg class="analytics-filter-search-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M21 21l-4.35-4.35m1.1-5.15a6.25 6.25 0 11-12.5 0 6.25 6.25 0 0112.5 0z"/>
                        </svg>
                        <input id="event-participant-search"
                               type="search"
                               name="q"
                               value="{{ $search }}"
                               wire:model="search"
                               autocomplete="off"
                               placeholder="Search name, ID, email"
                               class="form-input analytics-filter-control analytics-filter-search-input">
                    </div>
                </div>

                <div class="analytics-filter-field">
                    <label class="analytics-filter-label" for="event-participant-scope">Scope</label>
                    <select id="event-participant-scope" name="scope_id" wire:model="scopeId" class="form-select analytics-filter-control">
                        <option value="">All scopes</option>
                        @foreach($scopeOptions as $scope)
                            <option value="{{ $scope['id'] }}" @selected($scopeId === (string) $scope['id'])>{{ $scope['name'] }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="analytics-filter-field">
                    <label class="analytics-filter-label" for="event-participant-day">Day</label>
                    <select id="event-participant-day" name="day_id" wire:model="dayId" class="form-select analytics-filter-control">
                        <option value="">All days</option>
                        @foreach($dayOptions as $day)
                            <option value="{{ $day['id'] }}" @selected($dayId === (string) $day['id'])>{{ $day['label'] }} - {{ $day['date'] }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="analytics-filter-field">
                    <label class="analytics-filter-label" for="event-participant-status">Status</label>
                    <select id="event-participant-status" name="status" wire:model="status" class="form-select analytics-filter-control">
                        @foreach($statusOptions as $value => $text)
                            <option value="{{ $value }}" @selected($status === (string) $value)>{{ $text }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="analytics-filter-field">
                    <label class="analytics-filter-label" for="event-participant-validation">Validation</label>
                    <select id="event-participant-validation" name="validation" wire:model="validation" class="form-select analytics-filter-control">
                        @foreach($validationOptions as $value => $text)
                            <option value="{{ $value }}" @selected($validation === (string) $value)>{{ $text }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="analytics-filter-field">
                    <label class="analytics-filter-label" for="event-participant-year-level">Year level</label>
                    <select id="event-participant-year-level" name="year_level" wire:model="yearLevel" class="form-select analytics-filter-control">
                        <option value="">All year levels</option>
                        @foreach($yearLevelOptions as $value => $text)
                            <option value="{{ $value }}" @selected($yearLevel === (string) $value)>{{ $text }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="flex flex-wrap items-center justify-end gap-2">
                @if($activeFilterCount > 0)
                    <a href="{{ $filterResetUrl }}"
                            wire:click.prevent="clearFilters"
                            wire:target="applyFilters,clearFilters,perPage,nextPage,previousPage,gotoPage"
                            class="btn btn-sm btn-secondary">
                        <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                        Reset
                    </a>
                @endif
                <button type="submit"
                        wire:loading.attr="disabled"
                        wire:target="applyFilters,clearFilters,perPage,nextPage,previousPage,gotoPage"
                        class="btn btn-sm">
                    <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 4h18M6 12h12M10 20h4"/>
                    </svg>
                    <span wire:loading.remove wire:target="applyFilters,clearFilters">Apply Filters</span>
                    <span wire:loading wire:target="applyFilters,clearFilters">Applying...</span>
                </button>
            </div>
        </form>

        @if($activeFilterCount > 0)
            <div class="mt-3 flex flex-wrap items-center gap-2">
                <span class="text-xs font-semibold uppercase tracking-wider text-(--color-text-tertiary)">Active:</span>
                @if($search !== '')
                    <span class="analytics-chip analytics-tone-neutral"><span class="analytics-chip-dot"></span>Search: {{ $search }}</span>
                @endif
                @if($scopeId !== '')
                    <span class="analytics-chip analytics-tone-neutral"><span class="analytics-chip-dot"></span>Scope: {{ $selectedScope['name'] ?? 'Selected' }}</span>
                @endif
                @if($dayId !== '')
                    <span class="analytics-chip analytics-tone-neutral"><span class="analytics-chip-dot"></span>{{ $selectedDay ? $selectedDay['label'].' - '.$selectedDay['date'] : 'Day selected' }}</span>
                @endif
                @if($status !== 'all')
                    <span class="analytics-chip analytics-tone-warn"><span class="analytics-chip-dot"></span>{{ $statusOptions[$status] ?? $status }}</span>
                @endif
                @if($validation !== 'all')
                    <span class="analytics-chip analytics-tone-good"><span class="analytics-chip-dot"></span>{{ $validationOptions[$validation] ?? $validation }}</span>
                @endif
                @if($yearLevel !== '')
                    <span class="analytics-chip analytics-tone-good"><span class="analytics-chip-dot"></span>{{ $yearLevelOptions[$yearLevel] ?? $yearLevel }}</span>
                @endif
            </div>
        @endif
    </div>

    <div class="analytics-table-wrap"
         wire:loading.class="opacity-60"
         wire:target="applyFilters,clearFilters,perPage,nextPage,previousPage,gotoPage">
        <table class="analytics-table">
            <thead>
                <tr>
                    <th>Student</th>
                    <th>Scope</th>
                    <th>Status</th>
                    <th>Validation</th>
                    <th>Evaluation</th>
                    <th class="text-right">Time In / Out</th>
                </tr>
            </thead>
            <tbody>
                @forelse($participantRows as $row)
                    @php
                        $effectiveStatus = $row['day_status']['status'] ?? $row['status'];
                    @endphp
                    <tr wire:key="participant-drilldown-{{ $row['student_id'] }}">
                        <td>
                            <p class="font-semibold text-(--color-text-primary)">{{ $row['name'] }}</p>
                            <p class="text-xs text-(--color-text-tertiary)">{{ $row['student_id_number'] ?: $row['email'] }} - {{ $row['program_code'] ?: 'N/A' }} - {{ $row['year_level_label'] }}</p>
                            @unless($row['expected'])
                                <span class="mt-1 analytics-chip analytics-tone-warn"><span class="analytics-chip-dot"></span>Attended, but not part of this event's expected audience</span>
                            @endunless
                        </td>
                        <td class="text-xs text-(--color-text-secondary)">{{ $row['scope_names']->isNotEmpty() ? $row['scope_names']->join(', ') : 'No scope' }}</td>
                        <td><span class="analytics-chip {{ $toneClass($effectiveStatus) }}"><span class="analytics-chip-dot"></span>{{ $label($effectiveStatus) }}</span></td>
                        <td>
                            <span class="analytics-chip {{ $row['valid'] ? 'analytics-tone-good' : 'analytics-tone-warn' }}"><span class="analytics-chip-dot"></span>{{ $row['valid'] ? 'Valid' : 'Invalid or pending' }}</span>
                            @if($row['force_validated'])
                                <span class="block mt-1 text-[11px] text-(--color-text-tertiary)">Force validated</span>
                            @endif
                        </td>
                        <td><span class="analytics-chip {{ $row['evaluation_submitted'] ? 'analytics-tone-good' : 'analytics-tone-neutral' }}"><span class="analytics-chip-dot"></span>{{ $row['evaluation_submitted'] ? 'Submitted' : 'Missing' }}</span></td>
                        <td class="text-right text-xs font-data text-(--color-text-secondary)">{{ $row['time_in'] ?: 'None' }} / {{ $row['time_out'] ?: 'None' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="analytics-empty">No participants match the filters.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($participantRows->hasPages())
        <div class="p-4 border-t border-(--color-border)">
            {{ $participantRows->links() }}
        </div>
    @endif
</div>
