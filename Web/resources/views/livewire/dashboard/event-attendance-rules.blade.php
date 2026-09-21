<div class="proposal-modal-body space-y-4">
    @if($notice)
        <div class="event-workflow-feedback event-workflow-feedback--success"
             role="status"
             aria-atomic="true"
             x-data="{ visible: true }"
             x-show="visible"
             x-init="setTimeout(() => visible = false, 4000)"
             x-transition.opacity.duration.150ms>{{ $notice }}</div>
    @endif

    @if($errorNotice)
        <div class="event-workflow-feedback event-workflow-feedback--error" role="alert">{{ $errorNotice }}</div>
    @endif

    <section class="proposal-modal-section">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h3 class="font-semibold text-sm text-(--color-text-primary)">Event late cutoff</h3>
                <p class="text-xs text-(--color-text-tertiary)">When set, this value applies to every participant scope for this event.</p>
            </div>
            <span class="badge {{ $attendancePolicyLocked ? 'badge-rejected' : 'badge-approved' }}">
                {{ $attendancePolicyLocked ? 'Locked' : 'Editable' }}
            </span>
        </div>

        <div class="attendance-rule-current">
            <span class="attendance-rule-current-label">Current rule</span>
            <span class="attendance-rule-current-value">
                {{ $event->event_late_cutoff_min !== null ? $event->event_late_cutoff_min . ' min after start' : 'Participant fallback' }}
            </span>
        </div>

        @if($canManageEventRules)
            <form wire:submit="saveEventCutoff"
                  class="cutoff-editor-form"
                  x-data="{ cutoff: $wire.entangle('eventLateCutoffMin') }">
                <div class="cutoff-editor-main">
                    <label class="cutoff-input-field">
                        <span>Custom minutes</span>
                        <input id="event-cutoff-{{ $event->id }}"
                               type="number"
                               min="0"
                               max="240"
                               class="form-input text-sm @error('eventLateCutoffMin') form-input-error @enderror"
                               placeholder="Blank = scope fallback"
                               x-model="cutoff"
                               @disabled($attendancePolicyLocked)>
                    </label>
                    <button type="submit"
                            class="btn btn-sm btn-primary"
                            wire:loading.attr="disabled"
                            wire:target="saveEventCutoff"
                            @disabled($attendancePolicyLocked)>
                        <span wire:loading.remove wire:target="saveEventCutoff">Save Rule</span>
                        <span wire:loading wire:target="saveEventCutoff">Saving...</span>
                    </button>
                </div>
                @error('eventLateCutoffMin') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                <div class="cutoff-preset-group" aria-label="Suggested event late cutoffs">
                    <button type="button"
                            class="cutoff-preset"
                            data-cutoff-value="10"
                            x-bind:class="{ 'is-selected': String(cutoff) === '10' }"
                            x-bind:aria-pressed="String(cutoff) === '10'"
                            x-on:click="cutoff = 10"
                            @disabled($attendancePolicyLocked)>
                        <span class="cutoff-preset-value">10 min</span>
                        <span class="cutoff-preset-label">Tight</span>
                    </button>
                    <button type="button"
                            class="cutoff-preset"
                            data-cutoff-value="15"
                            x-bind:class="{ 'is-selected': String(cutoff) === '15' }"
                            x-bind:aria-pressed="String(cutoff) === '15'"
                            x-on:click="cutoff = 15"
                            @disabled($attendancePolicyLocked)>
                        <span class="cutoff-preset-value">15 min</span>
                        <span class="cutoff-preset-label">Standard</span>
                    </button>
                    <button type="button"
                            class="cutoff-preset"
                            data-cutoff-value="30"
                            x-bind:class="{ 'is-selected': String(cutoff) === '30' }"
                            x-bind:aria-pressed="String(cutoff) === '30'"
                            x-on:click="cutoff = 30"
                            @disabled($attendancePolicyLocked)>
                        <span class="cutoff-preset-value">30 min</span>
                        <span class="cutoff-preset-label">Flexible</span>
                    </button>
                    <button type="button"
                            class="cutoff-preset cutoff-preset-muted"
                            data-cutoff-value=""
                            x-bind:class="{ 'is-selected': cutoff === null || cutoff === '' }"
                            x-bind:aria-pressed="cutoff === null || cutoff === ''"
                            x-on:click="cutoff = null"
                            @disabled($attendancePolicyLocked)>
                        <span class="cutoff-preset-value">Fallback</span>
                        <span class="cutoff-preset-label">Per scope</span>
                    </button>
                </div>
            </form>
        @else
            <div class="mt-4 rounded-lg border border-(--color-border) bg-(--color-surface-raised) p-3">
                <p class="text-sm font-semibold text-(--color-text-primary)">
                    {{ $event->event_late_cutoff_min !== null ? $event->event_late_cutoff_min . ' minutes after start' : 'Participant fallback mode' }}
                </p>
                <p class="mt-1 text-xs text-(--color-text-tertiary)">Only the event organizer can set the event-level cutoff.</p>
            </div>
        @endif

        @if($attendancePolicyLocked)
            <p class="mt-3 text-xs font-semibold text-(--color-warning)">Attendance rules are not editable after the event starts.</p>
        @endif
    </section>

    <section class="proposal-modal-section">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h3 class="font-semibold text-sm text-(--color-text-primary)">Session schedule</h3>
                <p class="text-xs text-(--color-text-tertiary)">Multiple sessions let whole-day events pause scanning during lunch or breaks. Sessions may be back-to-back but cannot overlap.</p>
            </div>
            <span class="badge {{ $sessionEditingLocked ? 'badge-rejected' : 'badge-approved' }}">
                {{ $sessionEditingLocked ? 'Locked' : 'Editable' }}
            </span>
        </div>

        @if($canManageEventRules)
            <form wire:submit="saveSessions" class="mt-3 space-y-3">
                @foreach($sessionRows as $dayIndex => $day)
                    <div class="rounded-lg border border-(--color-border) bg-(--color-surface-raised) p-3" wire:key="session-day-{{ $day['id'] }}">
                        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <p class="text-sm font-semibold text-(--color-text-primary)">Day {{ $dayIndex + 1 }} · {{ $day['date'] }}</p>
                                <p class="text-xs text-(--color-text-tertiary)">Event day: {{ $day['start_time'] }}-{{ $day['end_time'] }}</p>
                            </div>
                            <button type="button"
                                    class="btn btn-sm btn-ghost"
                                    wire:click="addSession('{{ $day['id'] }}')"
                                    @disabled($sessionEditingLocked)>
                                Add session
                            </button>
                        </div>

                        <div class="mt-3 space-y-2">
                            @foreach($day['sessions'] as $sessionIndex => $session)
                                <div class="grid gap-2 sm:grid-cols-[1.2fr_0.8fr_0.8fr_auto_auto]" wire:key="session-row-{{ $day['id'] }}-{{ $sessionIndex }}">
                                    <label>
                                        <span class="proposal-mini-label">Name</span>
                                        <input type="text"
                                               class="form-input text-sm @error('sessionRows.'.$dayIndex.'.sessions.'.$sessionIndex.'.name') form-input-error @enderror"
                                               maxlength="80"
                                               wire:model="sessionRows.{{ $dayIndex }}.sessions.{{ $sessionIndex }}.name"
                                               @disabled($sessionEditingLocked)>
                                    </label>
                                    <label>
                                        <span class="proposal-mini-label">Start</span>
                                        <input type="time"
                                               class="form-input text-sm @error('sessionRows.'.$dayIndex.'.sessions.'.$sessionIndex.'.start_time') form-input-error @enderror"
                                               wire:model="sessionRows.{{ $dayIndex }}.sessions.{{ $sessionIndex }}.start_time"
                                               @disabled($sessionEditingLocked)>
                                    </label>
                                    <label>
                                        <span class="proposal-mini-label">End</span>
                                        <input type="time"
                                               class="form-input text-sm @error('sessionRows.'.$dayIndex.'.sessions.'.$sessionIndex.'.end_time') form-input-error @enderror"
                                               wire:model="sessionRows.{{ $dayIndex }}.sessions.{{ $sessionIndex }}.end_time"
                                               @disabled($sessionEditingLocked)>
                                    </label>
                                    <label class="proposal-inline-toggle self-end">
                                        <input type="checkbox"
                                               wire:model="sessionRows.{{ $dayIndex }}.sessions.{{ $sessionIndex }}.required_for_attendance"
                                               @disabled($sessionEditingLocked)>
                                        <span>Required</span>
                                    </label>
                                    <button type="button"
                                            class="btn-icon btn-ghost text-(--color-destructive) self-end"
                                            wire:click="removeSession('{{ $day['id'] }}', {{ $sessionIndex }})"
                                            @disabled($sessionEditingLocked || count($day['sessions']) <= 1)
                                            aria-label="Remove session">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                    </button>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endforeach

                @error('sessionRows') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                <div class="flex justify-end">
                    <button type="submit"
                            class="btn btn-sm btn-primary"
                            wire:loading.attr="disabled"
                            wire:target="saveSessions"
                            @disabled($sessionEditingLocked)>
                        <span wire:loading.remove wire:target="saveSessions">Save Sessions</span>
                        <span wire:loading wire:target="saveSessions">Saving...</span>
                    </button>
                </div>
            </form>
        @else
            <div class="mt-3 space-y-2">
                @foreach($sessionRows as $dayIndex => $day)
                    <div class="rounded-lg border border-(--color-border) bg-(--color-surface-raised) p-3">
                        <p class="text-sm font-semibold text-(--color-text-primary)">Day {{ $dayIndex + 1 }} · {{ $day['date'] }}</p>
                        <ul class="mt-2 space-y-1 text-xs text-(--color-text-secondary)">
                            @foreach($day['sessions'] as $session)
                                <li>{{ $session['name'] }} · {{ $session['start_time'] }}-{{ $session['end_time'] }} · {{ $session['required_for_attendance'] ? 'Required' : 'Optional' }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </div>
        @endif

        @if($sessionEditingLocked)
            <p class="mt-3 text-xs font-semibold text-(--color-warning)">Session schedules are locked after the event starts or once scans are captured.</p>
        @endif
    </section>

    <section class="proposal-modal-section">
        <div class="flex flex-col gap-1">
            <h3 class="font-semibold text-sm text-(--color-text-primary)">Participant fallback cutoffs</h3>
            <p class="text-xs text-(--color-text-tertiary)">Used only when the event late cutoff is blank.</p>
        </div>

        <div class="mt-3 space-y-3">
            @forelse($participantScopes as $scope)
                @php
                    $canEditScopeCutoff = (bool) ($canEditScopeCutoffs[$scope->id] ?? false);
                    $scopeCutoffModel = 'scopeLateCutoffMins.'.$scope->id;
                @endphp
                <div class="rounded-lg border border-(--color-border) bg-(--color-surface-raised) p-3">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <p class="text-sm font-semibold text-(--color-text-primary)">{{ $scope->displayName() }}</p>
                                <span class="badge badge-{{ $scope->ppa_status === 'approved' ? 'approved' : 'pending' }}">{{ ucwords(str_replace('_', ' ', $scope->ppa_status)) }}</span>
                            </div>
                            <p class="mt-1 text-xs text-(--color-text-tertiary)">
                                Current:
                                @if($event->event_late_cutoff_min !== null)
                                    {{ $event->event_late_cutoff_min }} min event rule
                                @elseif($scope->late_cutoff_min !== null)
                                    {{ $scope->late_cutoff_min }} min scope fallback
                                @else
                                    Default 15 min fallback
                                @endif
                            </p>
                        </div>

                        @if($canEditScopeCutoff)
                            <form wire:submit="saveScopeCutoff('{{ $scope->id }}')"
                                  class="scope-cutoff-form"
                                  x-data="{ cutoff: $wire.entangle('{{ $scopeCutoffModel }}') }">
                                <div class="scope-cutoff-main">
                                    <label class="cutoff-input-field">
                                        <span>Minutes</span>
                                        <input id="scope-cutoff-{{ $event->id }}-{{ $scope->id }}"
                                               type="number"
                                               min="0"
                                               max="240"
                                               class="form-input text-sm @error('scopeLateCutoffMins.'.$scope->id) form-input-error @enderror"
                                               placeholder="15"
                                               x-model="cutoff">
                                    </label>
                                    <button type="submit"
                                            class="btn btn-sm btn-secondary"
                                            wire:loading.attr="disabled"
                                            wire:target="saveScopeCutoff">
                                        <span wire:loading.remove wire:target="saveScopeCutoff">Save</span>
                                        <span wire:loading wire:target="saveScopeCutoff">Saving...</span>
                                    </button>
                                </div>
                                @error('scopeLateCutoffMins.'.$scope->id) <p class="form-error" role="alert">{{ $message }}</p> @enderror
                                <div class="cutoff-preset-group cutoff-preset-group-compact" aria-label="Suggested participant fallback cutoffs">
                                    <button type="button"
                                            class="cutoff-preset"
                                            data-cutoff-value="10"
                                            x-bind:class="{ 'is-selected': String(cutoff) === '10' }"
                                            x-bind:aria-pressed="String(cutoff) === '10'"
                                            x-on:click="cutoff = 10">
                                        <span class="cutoff-preset-value">10</span>
                                        <span class="cutoff-preset-label">Tight</span>
                                    </button>
                                    <button type="button"
                                            class="cutoff-preset"
                                            data-cutoff-value="15"
                                            x-bind:class="{ 'is-selected': String(cutoff) === '15' }"
                                            x-bind:aria-pressed="String(cutoff) === '15'"
                                            x-on:click="cutoff = 15">
                                        <span class="cutoff-preset-value">15</span>
                                        <span class="cutoff-preset-label">Std</span>
                                    </button>
                                    <button type="button"
                                            class="cutoff-preset"
                                            data-cutoff-value="30"
                                            x-bind:class="{ 'is-selected': String(cutoff) === '30' }"
                                            x-bind:aria-pressed="String(cutoff) === '30'"
                                            x-on:click="cutoff = 30">
                                        <span class="cutoff-preset-value">30</span>
                                        <span class="cutoff-preset-label">Flex</span>
                                    </button>
                                    <button type="button"
                                            class="cutoff-preset cutoff-preset-muted"
                                            data-cutoff-value=""
                                            x-bind:class="{ 'is-selected': cutoff === null || cutoff === '' }"
                                            x-bind:aria-pressed="cutoff === null || cutoff === ''"
                                            x-on:click="cutoff = null">
                                        <span class="cutoff-preset-value">Default</span>
                                        <span class="cutoff-preset-label">15 min</span>
                                    </button>
                                </div>
                            </form>
                        @else
                            <span class="text-xs font-medium text-(--color-text-tertiary)">
                                @if($event->event_late_cutoff_min !== null)
                                    Event rule applies
                                @elseif($attendancePolicyLocked)
                                    Locked
                                @else
                                    Read only
                                @endif
                            </span>
                        @endif
                    </div>
                </div>
            @empty
                <div class="rounded-lg border border-dashed border-(--color-border) bg-(--color-surface-muted) p-4">
                    <p class="text-sm font-medium text-(--color-text-primary)">No participant scopes yet</p>
                    <p class="mt-1 text-xs text-(--color-text-tertiary)">Participant fallback cutoffs appear after scopes are invited.</p>
                </div>
            @endforelse
        </div>
    </section>
</div>
