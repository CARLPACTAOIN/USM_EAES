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
