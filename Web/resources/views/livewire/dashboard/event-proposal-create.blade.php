@php($proposalModalId = $editing ? 'edit-event-modal-'.$eventId : 'create-event-modal')

<form method="POST"
      action="{{ $editing ? route('dashboard.events.update', $eventId) : route('dashboard.events.create') }}"
      enctype="multipart/form-data"
      wire:submit.prevent="createProposal"
      class="proposal-modal-form">
    @csrf
    <div class="proposal-modal-header">
        <div class="proposal-modal-title-row">
            <span class="proposal-modal-icon" aria-hidden="true">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414A1 1 0 0119 9.414V19a2 2 0 01-2 2z"/></svg>
            </span>
            <div>
                <p class="proposal-modal-kicker">PPA proposal packet</p>
                <h2 id="{{ $editing ? 'edit-event-modal-title-'.$eventId : 'create-event-modal-title' }}" class="proposal-modal-title">
                    {{ $editing ? 'Revise Event Proposal' : 'New Event Proposal' }}
                </h2>
                <p id="{{ $editing ? 'edit-event-modal-description-'.$eventId : 'create-event-modal-description' }}" class="proposal-modal-subtitle">
                    {{ $editing ? 'Update the returned canonical packet before resubmitting to OSA.' : 'Build one canonical packet for proposal and permit details.' }}
                </p>
            </div>
        </div>
        <button type="button" onclick="document.getElementById('{{ $proposalModalId }}')?.close()" class="btn-icon btn-ghost" aria-label="Close proposal modal">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
    </div>

    <div class="proposal-modal-body">
        @if($errorNotice)
            <div id="proposal-create-error-notice" class="proposal-error-summary" role="alert" aria-live="assertive" tabindex="-1">
                <p>{{ $errorNotice }}</p>
            </div>
        @endif

        @if($errors->any())
            <div id="proposal-create-error-summary" class="proposal-error-summary" role="alert" aria-live="assertive" tabindex="-1">
                <p>A few details need attention before this proposal can be saved.</p>
                <ul>
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <fieldset class="proposal-modal-section">
            <legend class="proposal-section-title">
                <span class="proposal-section-step">1</span>
                Proposal identity
            </legend>

            <div class="proposal-grid">
                @if($isOsaCreator)
                    <div class="proposal-grid-full">
                        <label for="livewire-organization-id" class="form-label">Organizer <span class="text-(--color-destructive)">*</span></label>
                        <select id="livewire-organization-id" name="organization_id" wire:model="organizerId" class="form-input form-select @error('organizerId') form-input-error @enderror" required>
                            <option value="">Select organizer</option>
                            @foreach($organizerOptions as $org)
                                <option value="{{ $org->id }}" @selected($organizerId === (string) $org->id)>{{ $org->acronym }} - {{ $org->name }}</option>
                            @endforeach
                        </select>
                        @error('organizerId') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                    </div>
                @endif

                <div class="proposal-grid-full">
                    <label for="livewire-title" class="form-label">Event Title <span class="text-(--color-destructive)">*</span></label>
                    <input type="text" id="livewire-title" name="title" class="form-input @error('title') form-input-error @enderror" required maxlength="255"
                           placeholder="e.g. PSITS General Assembly 2026" value="{{ $title }}" wire:model="title">
                    @error('title') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="livewire-proposal-category" class="form-label">PPA Category <span class="text-(--color-destructive)">*</span></label>
                    <select id="livewire-proposal-category" name="proposal_category" class="form-input form-select @error('proposalCategory') form-input-error @enderror" required wire:model="proposalCategory">
                        <option value="">Select category</option>
                        @foreach(['program' => 'Program', 'project' => 'Project', 'activity' => 'Activity'] as $value => $label)
                            <option value="{{ $value }}" @selected($proposalCategory === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('proposalCategory') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="livewire-activity-role" class="form-label">Activity Role <span class="text-(--color-destructive)">*</span></label>
                    <select id="livewire-activity-role" name="activity_role" class="form-input form-select @error('activityRole') form-input-error @enderror" required wire:model="activityRole">
                        @foreach(['organizer' => 'Organizer', 'co-organizer' => 'Co-Organizer', 'participant' => 'Participant'] as $value => $label)
                            <option value="{{ $value }}" @selected($activityRole === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <p class="form-helper">Used by the Student Activity Permit route.</p>
                    @error('activityRole') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="livewire-parent-event-id" class="form-label">Parent Permit</label>
                    <select id="livewire-parent-event-id" name="parent_event_id" class="form-input form-select @error('parentEventId') form-input-error @enderror" wire:model="parentEventId">
                        <option value="">None</option>
                        @foreach($parentEvents as $parentEvent)
                            <option value="{{ $parentEvent->id }}" @selected($parentEventId === (string) $parentEvent->id)>{{ $parentEvent->organization?->acronym }} - {{ Str::limit($parentEvent->title, 48) }}</option>
                        @endforeach
                    </select>
                    <p class="form-helper">For sub-events under USG or LSG permits.</p>
                    @error('parentEventId') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                </div>
            </div>
        </fieldset>

        <fieldset class="proposal-modal-section">
            <legend class="proposal-section-title">
                <span class="proposal-section-step">2</span>
                Canonical packet details
            </legend>

            <div class="proposal-grid">
                <div>
                    <label for="livewire-proponent-name" class="form-label">Proponent Name</label>
                    <input type="text" id="livewire-proponent-name" name="proponent_name" class="form-input @error('proponentName') form-input-error @enderror"
                           maxlength="255" value="{{ $proponentName }}" wire:model="proponentName">
                    @error('proponentName') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="livewire-proponent-position" class="form-label">Proponent Position</label>
                    <input type="text" id="livewire-proponent-position" name="proponent_position" class="form-input @error('proponentPosition') form-input-error @enderror"
                           maxlength="255" placeholder="e.g. Secretary" value="{{ $proponentPosition }}" wire:model="proponentPosition">
                    @error('proponentPosition') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="livewire-proponent-organization" class="form-label">Proponent Organization</label>
                    <input type="text" id="livewire-proponent-organization" name="proponent_organization" class="form-input @error('proponentOrganization') form-input-error @enderror"
                           maxlength="255" value="{{ $proponentOrganization }}" wire:model="proponentOrganization">
                    @error('proponentOrganization') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="livewire-proponent-email" class="form-label">Proponent Email</label>
                    <input type="email" id="livewire-proponent-email" name="proponent_email" class="form-input @error('proponentEmail') form-input-error @enderror"
                           maxlength="255" value="{{ $proponentEmail }}" wire:model="proponentEmail">
                    @error('proponentEmail') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                </div>
                <div class="proposal-grid-full">
                    <label for="livewire-proponent-contact" class="form-label">Proponent Contact Number</label>
                    <input type="text" id="livewire-proponent-contact" name="proponent_contact_number" class="form-input @error('proponentContactNumber') form-input-error @enderror"
                           maxlength="50" value="{{ $proponentContactNumber }}" wire:model="proponentContactNumber">
                    @error('proponentContactNumber') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                </div>
                <div class="proposal-grid-full">
                    <label for="livewire-rationale" class="form-label">Rationale / Significance</label>
                    <textarea id="livewire-rationale" name="rationale" rows="3" class="form-input @error('rationale') form-input-error @enderror"
                              wire:model="rationale">{{ $rationale }}</textarea>
                    @error('rationale') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                </div>
                <div class="proposal-grid-full">
                    <label for="livewire-general-objective" class="form-label">General Objective</label>
                    <textarea id="livewire-general-objective" name="general_objective" rows="2" class="form-input @error('generalObjective') form-input-error @enderror"
                              wire:model="generalObjective">{{ $generalObjective }}</textarea>
                    @error('generalObjective') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="livewire-specific-objectives" class="form-label">Specific Objectives</label>
                    <textarea id="livewire-specific-objectives" name="specific_objectives" rows="4" class="form-input @error('specificObjectives') form-input-error @enderror"
                              placeholder="One objective per line" wire:model="specificObjectives">{{ $specificObjectives }}</textarea>
                    @error('specificObjectives') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="livewire-major-activities" class="form-label">Major Activities</label>
                    <textarea id="livewire-major-activities" name="major_activities" rows="4" class="form-input @error('majorActivities') form-input-error @enderror"
                              placeholder="One major activity per line" wire:model="majorActivities">{{ $majorActivities }}</textarea>
                    @error('majorActivities') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                </div>
                <div class="proposal-grid-full">
                    <label for="livewire-methodology" class="form-label">Methodology / Mechanics and Guidelines</label>
                    <textarea id="livewire-methodology" name="methodology" rows="3" class="form-input @error('methodology') form-input-error @enderror"
                              wire:model="methodology">{{ $methodology }}</textarea>
                    @error('methodology') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                </div>
                <div class="proposal-grid-full">
                    <label for="livewire-expected-output" class="form-label">Expected Output</label>
                    <textarea id="livewire-expected-output" name="expected_output" rows="3" class="form-input @error('expectedOutput') form-input-error @enderror"
                              wire:model="expectedOutput">{{ $expectedOutput }}</textarea>
                    @error('expectedOutput') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                </div>
            </div>
        </fieldset>

        <fieldset class="proposal-modal-section"
                  x-data="eventScheduleEditor(
                      $wire.entangle('eventDays'),
                      $wire.entangle('startDate'),
                      $wire.entangle('endDate'),
                      $wire.entangle('sameTimeForAllDays'),
                      @js($errors->getMessages())
                  )">
            <legend class="proposal-section-title">
                <span class="proposal-section-step">3</span>
                Schedule and attendance
            </legend>

            <div class="proposal-grid">
                <div>
                    <label for="livewire-start-date" class="form-label">Start Date <span class="text-(--color-destructive)">*</span></label>
                    <input type="date" id="livewire-start-date" name="start_date" class="form-input @error('startDate') form-input-error @enderror"
                           required value="{{ $startDate }}" x-model="startDate" x-bind:max="endDate" x-on:change="handleStartDateChange()">
                    @error('startDate') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="livewire-end-date" class="form-label">End Date <span class="text-(--color-destructive)">*</span></label>
                    <input type="date" id="livewire-end-date" name="end_date" class="form-input @error('endDate') form-input-error @enderror"
                           required value="{{ $endDate }}" x-model="endDate" x-bind:min="startDate" x-on:change="handleEndDateChange()">
                    @error('endDate') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                </div>

                <div class="proposal-grid-full">
                    <div class="proposal-days-header">
                        <div>
                            <h3 class="proposal-subsection-title">Daily attendance schedule</h3>
                            <p class="form-helper" style="margin-top: 0.125rem;">Scanner windows are generated from the event date range.</p>
                        </div>
                        <div class="proposal-schedule-actions">
                            <label class="proposal-inline-toggle">
                                <input type="hidden" name="same_time_for_all_days" value="0">
                                <input type="checkbox"
                                       name="same_time_for_all_days"
                                       value="1"
                                       x-model="sameTimeForAllDays"
                                       x-on:change="if (sameTimeForAllDays) applyFirstDayTimesToAllDays()">
                                <span>Same time for all days</span>
                            </label>
                            <button type="button" x-on:click="addDay()" class="btn btn-sm btn-ghost">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                                Add Day
                            </button>
                        </div>
                    </div>
                    <template x-for="(day, index) in days" x-bind:key="index">
                        <div class="rounded-xl border border-(--color-border) bg-(--color-surface-muted) p-3">
                            <div class="proposal-day-row">
                                <span class="proposal-day-index" x-text="`D${index + 1}`"></span>
                                <div>
                                    <label x-bind:for="`livewire-day-date-${index}`" class="proposal-mini-label">Date</label>
                                    <input type="date"
                                           x-bind:id="`livewire-day-date-${index}`"
                                           name="event_days[0][date]"
                                           x-bind:name="`event_days[${index}][date]`"
                                           class="form-input text-sm"
                                           x-bind:class="{ 'form-input-error': dayError(index, 'date') }"
                                           required
                                           value="{{ $eventDays[0]['date'] ?? '' }}"
                                           x-model="day.date"
                                           x-bind:min="startDate"
                                           x-bind:max="endDate"
                                           x-on:input="clearScheduleErrors()">
                                    <template x-if="dayError(index, 'date')">
                                        <p class="form-error" role="alert" x-text="dayError(index, 'date')"></p>
                                    </template>
                                </div>
                                <div>
                                    <label x-bind:for="`livewire-day-start-${index}`" class="proposal-mini-label">Start</label>
                                    <input type="time"
                                           x-bind:id="`livewire-day-start-${index}`"
                                           name="event_days[0][start_time]"
                                           x-bind:name="`event_days[${index}][start_time]`"
                                           class="form-input text-sm"
                                           x-bind:class="{ 'form-input-error': dayError(index, 'start_time') }"
                                           required
                                           value="{{ $eventDays[0]['start_time'] ?? '' }}"
                                           x-model="day.start_time"
                                           x-on:input="clearScheduleErrors(); syncDayTime(index, 'start_time')">
                                    <template x-if="dayError(index, 'start_time')">
                                        <p class="form-error" role="alert" x-text="dayError(index, 'start_time')"></p>
                                    </template>
                                </div>
                                <div>
                                    <label x-bind:for="`livewire-day-end-${index}`" class="proposal-mini-label">End</label>
                                    <input type="time"
                                           x-bind:id="`livewire-day-end-${index}`"
                                           name="event_days[0][end_time]"
                                           x-bind:name="`event_days[${index}][end_time]`"
                                           class="form-input text-sm"
                                           x-bind:class="{ 'form-input-error': dayError(index, 'end_time') }"
                                           required
                                           value="{{ $eventDays[0]['end_time'] ?? '' }}"
                                           x-model="day.end_time"
                                           x-on:input="clearScheduleErrors(); syncDayTime(index, 'end_time')">
                                    <template x-if="dayError(index, 'end_time')">
                                        <p class="form-error" role="alert" x-text="dayError(index, 'end_time')"></p>
                                    </template>
                                </div>
                                <button type="button"
                                        x-on:click="removeDay(index)"
                                        class="btn-icon btn-ghost text-(--color-destructive)"
                                        x-bind:disabled="days.length <= 1"
                                        x-bind:aria-label="`Remove day ${index + 1}`">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                </button>
                            </div>

                            <div class="mt-3 border-t border-(--color-border) pt-3">
                                <div class="proposal-days-header">
                                    <div>
                                        <h4 class="proposal-mini-label">Attendance sessions</h4>
                                        <p class="form-helper" style="margin-top: 0.125rem;">Use gaps for lunch/breaks. Sessions cannot overlap.</p>
                                    </div>
                                    <div class="proposal-schedule-actions">
                                        <button type="button" x-on:click="splitDay(index)" class="btn btn-sm btn-ghost">Split day</button>
                                        <button type="button" x-on:click="addSession(index)" class="btn btn-sm btn-ghost">Add session</button>
                                    </div>
                                </div>
                                <template x-for="(session, sessionIndex) in ensureDaySessions(day)" x-bind:key="sessionIndex">
                                    <div class="proposal-day-row mt-2">
                                        <span class="proposal-day-index" x-text="`S${sessionIndex + 1}`"></span>
                                        <div>
                                            <label x-bind:for="`livewire-session-name-${index}-${sessionIndex}`" class="proposal-mini-label">Name</label>
                                            <input type="text"
                                                   x-bind:id="`livewire-session-name-${index}-${sessionIndex}`"
                                                   x-bind:name="`event_days[${index}][sessions][${sessionIndex}][name]`"
                                                   class="form-input text-sm"
                                                   maxlength="80"
                                                   x-bind:class="{ 'form-input-error': sessionError(index, sessionIndex, 'name') }"
                                                   x-model="session.name"
                                                   x-on:input="clearScheduleErrors()">
                                            <template x-if="sessionError(index, sessionIndex, 'name')">
                                                <p class="form-error" role="alert" x-text="sessionError(index, sessionIndex, 'name')"></p>
                                            </template>
                                        </div>
                                        <div>
                                            <label x-bind:for="`livewire-session-start-${index}-${sessionIndex}`" class="proposal-mini-label">Time-In opens</label>
                                            <input type="time"
                                                   x-bind:id="`livewire-session-start-${index}-${sessionIndex}`"
                                                   x-bind:name="`event_days[${index}][sessions][${sessionIndex}][start_time]`"
                                                   class="form-input text-sm"
                                                   required
                                                   x-bind:class="{ 'form-input-error': sessionError(index, sessionIndex, 'start_time') }"
                                                   x-model="session.start_time"
                                                   x-on:input="clearScheduleErrors()">
                                            <template x-if="sessionError(index, sessionIndex, 'start_time')">
                                                <p class="form-error" role="alert" x-text="sessionError(index, sessionIndex, 'start_time')"></p>
                                            </template>
                                        </div>
                                        <div>
                                            <label x-bind:for="`livewire-session-end-${index}-${sessionIndex}`" class="proposal-mini-label">Time-Out closes</label>
                                            <input type="time"
                                                   x-bind:id="`livewire-session-end-${index}-${sessionIndex}`"
                                                   x-bind:name="`event_days[${index}][sessions][${sessionIndex}][end_time]`"
                                                   class="form-input text-sm"
                                                   required
                                                   x-bind:class="{ 'form-input-error': sessionError(index, sessionIndex, 'end_time') }"
                                                   x-model="session.end_time"
                                                   x-on:input="clearScheduleErrors()">
                                            <template x-if="sessionError(index, sessionIndex, 'end_time')">
                                                <p class="form-error" role="alert" x-text="sessionError(index, sessionIndex, 'end_time')"></p>
                                            </template>
                                        </div>
                                        <label class="proposal-inline-toggle">
                                            <input type="hidden" x-bind:name="`event_days[${index}][sessions][${sessionIndex}][required_for_attendance]`" value="0">
                                            <input type="checkbox"
                                                   x-bind:name="`event_days[${index}][sessions][${sessionIndex}][required_for_attendance]`"
                                                   value="1"
                                                   x-model="session.required_for_attendance">
                                            <span>Required</span>
                                        </label>
                                        <button type="button"
                                                x-on:click="removeSession(index, sessionIndex)"
                                                class="btn-icon btn-ghost text-(--color-destructive)"
                                                x-bind:disabled="ensureDaySessions(day).length <= 1"
                                                x-bind:aria-label="`Remove session ${sessionIndex + 1}`">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                        </button>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </template>
                    @error('eventDays') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="livewire-location-type" class="form-label">Location Type <span class="text-(--color-destructive)">*</span></label>
                    <select id="livewire-location-type" name="location_type" class="form-input form-select @error('locationType') form-input-error @enderror" required wire:model.live="locationType">
                        <option value="on-campus" @selected($locationType === 'on-campus')>On-Campus</option>
                        <option value="off-campus" @selected($locationType === 'off-campus')>Off-Campus</option>
                        <option value="online" @selected($locationType === 'online')>Online</option>
                    </select>
                    @error('locationType') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                </div>
                @if($locationType === 'on-campus')
                    <div>
                        <label for="livewire-venue-id" class="form-label">Confirmed Campus Venue</label>
                        <select id="livewire-venue-id" name="venue_id" class="form-input form-select @error('venueId') form-input-error @enderror" wire:model.live="venueId">
                            <option value="">Manual campus venue / not in catalog</option>
                            @foreach($activeVenues as $venue)
                                <option value="{{ $venue->id }}">{{ $venue->name }} — {{ $venue->campus_location }} ({{ $venue->managing_office }})</option>
                            @endforeach
                        </select>
                        <p class="form-helper">Catalog is a typing shortcut only. Contact the managing office outside EAES, then record the confirmation below.</p>
                        @error('venueId') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="livewire-location-details" class="form-label">Manual Campus Venue Name</label>
                        <input type="text" id="livewire-location-details" name="location_details" class="form-input @error('locationDetails') form-input-error @enderror"
                               placeholder="e.g. Main Gym" value="{{ $locationDetails }}" wire:model.live.debounce.300ms="locationDetails">
                        <p class="form-helper">Use when the venue is not in the catalog. This will not add a catalog record.</p>
                        @error('locationDetails') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                    </div>
                @elseif($locationType === 'off-campus')
                    <div>
                        <label for="livewire-location-details" class="form-label">Off-Campus Venue Name and Address</label>
                        <input type="text" id="livewire-location-details" name="location_details" class="form-input @error('locationDetails') form-input-error @enderror"
                               placeholder="Venue name, street, municipality" value="{{ $locationDetails }}" wire:model="locationDetails">
                        @error('locationDetails') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                    </div>
                @else
                    <div>
                        <label for="livewire-location-details" class="form-label">Online Platform or Link</label>
                        <input type="text" id="livewire-location-details" name="location_details" class="form-input @error('locationDetails') form-input-error @enderror"
                               placeholder="e.g. Google Meet link or LMS room" value="{{ $locationDetails }}" wire:model="locationDetails">
                        @error('locationDetails') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                    </div>
                @endif

                @if($locationType === 'on-campus')
                    <div class="proposal-grid-full">
                        <div class="proposal-file-box">
                            <span class="proposal-file-icon" aria-hidden="true">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M8 7V3m8 4V3M5 11h14M6 21h12a2 2 0 002-2V7a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                            </span>
                            <div>
                                <p class="form-label">Venue availability check</p>
                                @if(count($venueAvailabilityPreview['confirmed']) > 0)
                                    <div class="proposal-error-summary" role="status">
                                        <p>Confirmed system-recorded usage overlaps this venue/time.</p>
                                        <ul>
                                            @foreach(array_slice($venueAvailabilityPreview['confirmed'], 0, 3) as $event)
                                                <li>{{ $event['title'] }} · {{ $event['date'] }} {{ $event['start_time'] }}-{{ $event['end_time'] }} · buffered {{ $event['buffer_start_time'] }}-{{ $event['buffer_end_time'] }}</li>
                                            @endforeach
                                        </ul>
                                    </div>
                                @elseif(count($venueAvailabilityPreview['tentative']) > 0)
                                    <div class="rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900" role="status">
                                        <p class="font-semibold">Tentative system-recorded usage exists for this venue/time.</p>
                                        <ul class="mt-1 list-disc pl-4 text-xs">
                                            @foreach(array_slice($venueAvailabilityPreview['tentative'], 0, 3) as $event)
                                                <li>{{ $event['title'] }} · {{ $event['date'] }} {{ $event['start_time'] }}-{{ $event['end_time'] }}</li>
                                            @endforeach
                                        </ul>
                                    </div>
                                @else
                                    <p class="form-helper">No overlapping system-recorded usage found for the selected venue and schedule.</p>
                                @endif
                                <p class="form-helper">{{ $venueAvailabilityPreview['disclaimer'] }}</p>
                                @if($venueAvailabilityPreview['availability_url'])
                                    <a href="{{ $venueAvailabilityPreview['availability_url'] }}" class="text-sm font-medium text-(--color-accent) hover:underline" target="_blank" rel="noopener">
                                        Open full availability view
                                    </a>
                                @endif
                            </div>
                        </div>
                    </div>
                @endif
            </div>
        </fieldset>

        @if($locationType === 'off-campus')
        <fieldset class="proposal-modal-section">
            <legend class="proposal-section-title">
                <span class="proposal-section-step">4</span>
                Off-campus venue confirmation
            </legend>

            <div class="proposal-grid">
                <div>
                    <label for="livewire-confirmation-office" class="form-label">Confirming Office</label>
                    <input type="text" id="livewire-confirmation-office" name="venue_confirmation_office"
                           class="form-input @error('venueConfirmationOffice') form-input-error @enderror"
                           placeholder="Office that confirmed the reservation" maxlength="255"
                           value="{{ $venueConfirmationOffice }}" wire:model="venueConfirmationOffice">
                    @error('venueConfirmationOffice') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="livewire-confirmation-date" class="form-label">External Confirmation Date</label>
                    <input type="date" id="livewire-confirmation-date" name="venue_confirmation_date"
                           max="{{ today()->toDateString() }}"
                           class="form-input @error('venueConfirmationDate') form-input-error @enderror"
                           value="{{ $venueConfirmationDate }}" wire:model="venueConfirmationDate">
                    @error('venueConfirmationDate') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                </div>
                <div class="proposal-grid-full">
                    <label for="livewire-confirmation-reference" class="form-label">Confirmation Reference Number</label>
                    <input type="text" id="livewire-confirmation-reference" name="venue_confirmation_reference_number"
                           class="form-input @error('venueConfirmationReferenceNumber') form-input-error @enderror"
                           placeholder="Optional memo, email, or transaction reference" maxlength="255"
                           value="{{ $venueConfirmationReferenceNumber }}" wire:model="venueConfirmationReferenceNumber">
                    @error('venueConfirmationReferenceNumber') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                </div>
                <div class="proposal-grid-full">
                    <div class="proposal-file-box">
                        <div>
                            <label for="livewire-confirmation-document" class="form-label">Confirmation Evidence</label>
                            <input type="file" id="livewire-confirmation-document" name="venue_confirmation_document"
                                   class="form-input @error('venueConfirmationDocument') form-input-error @enderror"
                                   accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"
                                   wire:model="venueConfirmationDocument">
                            <p class="form-helper">PDF, DOC, DOCX, JPG, or PNG up to 10 MB. @if($currentVenueConfirmationDocumentName) Current: {{ $currentVenueConfirmationDocumentName }}. @endif</p>
                            <p wire:loading wire:target="venueConfirmationDocument" class="form-helper">Uploading evidence...</p>
                            @error('venueConfirmationDocument') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                        </div>
                    </div>
                </div>
            </div>

            <div class="proposal-question-preview" aria-live="polite" aria-label="Submission readiness">
                @foreach($readinessItems as $label => $complete)
                    <div class="proposal-question-preview-item">
                        <span class="proposal-question-preview-index">{{ $complete ? '✓' : '•' }}</span>
                        <span>{{ $label }}</span>
                    </div>
                @endforeach
            </div>
            <p class="form-helper">You may save an incomplete draft. Every item above is required before submission to OSA.</p>
        </fieldset>
        @else
            <div class="proposal-question-preview" aria-live="polite" aria-label="Submission readiness">
                @foreach($readinessItems as $label => $complete)
                    <div class="proposal-question-preview-item">
                        <span class="proposal-question-preview-index">{{ $complete ? '✓' : '•' }}</span>
                        <span>{{ $label }}</span>
                    </div>
                @endforeach
            </div>
        @endif

        <fieldset class="proposal-modal-section">
            <legend class="proposal-section-title">
                <span class="proposal-section-step">5</span>
                Offices, participants, and budget
            </legend>

            <div class="proposal-grid">
                <div>
                    <label for="livewire-implementing-office" class="form-label">Implementing Office/Organization</label>
                    <input type="text" id="livewire-implementing-office" name="implementing_office" class="form-input @error('implementingOffice') form-input-error @enderror"
                           placeholder="e.g. PSITS" value="{{ $implementingOffice }}" wire:model="implementingOffice">
                    @error('implementingOffice') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="livewire-collaborating-office" class="form-label">Collaborating Office/Organization</label>
                    <input type="text" id="livewire-collaborating-office" name="collaborating_office" class="form-input @error('collaboratingOffice') form-input-error @enderror"
                           placeholder="Optional partner office" value="{{ $collaboratingOffice }}" wire:model="collaboratingOffice">
                    @error('collaboratingOffice') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                </div>

                <div class="proposal-grid-full">
                    <label for="livewire-target-participants" class="form-label">Target Beneficiaries / Participants</label>
                    <textarea id="livewire-target-participants" name="target_participants" rows="3" class="form-input @error('targetParticipants') form-input-error @enderror"
                              placeholder="Who will attend, approximate count, and expected benefit" wire:model="targetParticipants">{{ $targetParticipants }}</textarea>
                    @error('targetParticipants') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="livewire-source-of-fund" class="form-label">Source of Fund</label>
                    <input type="text" id="livewire-source-of-fund" name="source_of_fund" class="form-input @error('sourceOfFund') form-input-error @enderror"
                           placeholder="e.g. Organization fund" value="{{ $sourceOfFund }}" wire:model="sourceOfFund">
                    @error('sourceOfFund') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="livewire-budget-cost" class="form-label">Budget / Cost (PHP)</label>
                    <input type="number" min="0" step="0.01" id="livewire-budget-cost" name="budget_cost" class="form-input @error('budgetCost') form-input-error @enderror"
                           placeholder="0.00" value="{{ $budgetCost }}" wire:model="budgetCost">
                    @error('budgetCost') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                </div>
            </div>
        </fieldset>

        <fieldset class="proposal-modal-section">
            <legend class="proposal-section-title">
                <span class="proposal-section-step">6</span>
                Optional supporting records
            </legend>

            <div class="proposal-grid">
                <div class="proposal-grid-full">
                    <div class="proposal-file-box">
                        <span class="proposal-file-icon" aria-hidden="true">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M12 16v-8m0 0l-3 3m3-3l3 3M4 16.5V18a2 2 0 002 2h12a2 2 0 002-2v-1.5"/></svg>
                        </span>
                        <div>
                            <label for="livewire-proposal-document" class="form-label">Legacy PPA Proposal Softcopy <span class="text-(--color-text-tertiary)">(optional)</span></label>
                            <input type="file" id="livewire-proposal-document" name="proposal_document"
                                   class="form-input @error('proposalDocument') form-input-error @enderror"
                                   accept=".pdf,.doc,.docx,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document"
                                   wire:model="proposalDocument">
                            <p class="form-helper">
                                Accepted: PDF, DOC, DOCX up to 10 MB. The generated proposal preview now comes from the canonical packet.
                                @if($editing && $currentProposalDocumentName)
                                    Current file: {{ $currentProposalDocumentName }}.
                                @endif
                            </p>
                            <p wire:loading wire:target="proposalDocument" class="form-helper">Uploading softcopy...</p>
                            @error('proposalDocument') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                        </div>
                    </div>
                </div>

                <div class="proposal-grid-full">
                    <label for="livewire-resolution-number" class="form-label">Resolution Number</label>
                    <input type="text" id="livewire-resolution-number" name="resolution_number" class="form-input @error('resolutionNumber') form-input-error @enderror"
                           placeholder="For USG/LSG proposals when applicable" value="{{ $resolutionNumber }}" wire:model="resolutionNumber">
                    @error('resolutionNumber') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                </div>
            </div>
        </fieldset>

        <fieldset class="proposal-modal-section">
            <legend class="proposal-section-title">
                <span class="proposal-section-step">7</span>
                Evaluation form
            </legend>
            @php($evaluationEditorOpen = $errors->has('evaluationQuestions') || $errors->has('evaluationQuestions.*.label') || $errors->has('evaluationQuestions.*.description'))
            <div class="proposal-question-preview" aria-label="Evaluation questions preview">
                @foreach($evaluationQuestions as $index => $question)
                    <div class="proposal-question-preview-item">
                        <span class="proposal-question-preview-index">Q{{ $index + 1 }}</span>
                        <span>{{ filled($question['label'] ?? '') ? $question['label'] : 'Untitled question' }}</span>
                    </div>
                @endforeach
            </div>

            <details class="proposal-evaluation-editor" @if($evaluationEditorOpen) open @endif>
                <summary class="proposal-evaluation-summary">
                    <span>Customize questions</span>
                    <span>{{ count($evaluationQuestions) }}/10</span>
                </summary>
                <div class="proposal-evaluation-editor-body">
                    <div class="proposal-days-header">
                        <p class="form-helper" style="margin-top: 0;">Rating questions use the fixed 1-5 scale.</p>
                        <button type="button" wire:click="addQuestion" class="btn btn-sm btn-ghost" @disabled(count($evaluationQuestions) >= 10)>
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                            Add Question
                        </button>
                    </div>
                    @foreach($evaluationQuestions as $index => $question)
                        <div class="proposal-evaluation-question-row" wire:key="evaluation-question-{{ $index }}">
                            <span class="proposal-day-index">Q{{ $index + 1 }}</span>
                            <input type="hidden" name="evaluation_questions[{{ $index }}][id]" value="{{ $question['id'] ?? '' }}" wire:model="evaluationQuestions.{{ $index }}.id">
                            <div>
                                <label for="livewire-question-label-{{ $index }}" class="proposal-mini-label">Question <span class="text-(--color-destructive)">*</span></label>
                                <input type="text"
                                       id="livewire-question-label-{{ $index }}"
                                       name="evaluation_questions[{{ $index }}][label]"
                                       class="form-input text-sm @error('evaluationQuestions.'.$index.'.label') form-input-error @enderror"
                                       maxlength="180"
                                       required
                                       value="{{ $question['label'] ?? '' }}"
                                       wire:model="evaluationQuestions.{{ $index }}.label">
                                @error('evaluationQuestions.'.$index.'.label') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label for="livewire-question-description-{{ $index }}" class="proposal-mini-label">Description</label>
                                <textarea id="livewire-question-description-{{ $index }}"
                                          name="evaluation_questions[{{ $index }}][description]"
                                          class="form-input text-sm @error('evaluationQuestions.'.$index.'.description') form-input-error @enderror"
                                          rows="2"
                                          maxlength="500"
                                          wire:model="evaluationQuestions.{{ $index }}.description">{{ $question['description'] ?? '' }}</textarea>
                                @error('evaluationQuestions.'.$index.'.description') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                            </div>
                            <button type="button" wire:click="removeQuestion({{ $index }})" class="btn-icon btn-ghost text-(--color-destructive)" @disabled(count($evaluationQuestions) <= 1) aria-label="Remove evaluation question">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                            </button>
                        </div>
                    @endforeach
                </div>
            </details>
            <p class="form-helper">The open comment field is always included.</p>
            @error('evaluationQuestions') <p class="form-error" role="alert">{{ $message }}</p> @enderror
        </fieldset>
    </div>

    <div class="proposal-modal-footer">
        <p class="proposal-required-note"><span class="text-(--color-destructive)">*</span> Required fields apply before {{ $editing ? 'saving revisions' : 'saving the draft' }}.</p>
        <div class="proposal-footer-actions">
            <button type="button" onclick="document.getElementById('{{ $proposalModalId }}')?.close()" class="btn btn-secondary">Cancel</button>
            <button type="submit"
                    class="btn btn-primary"
                    wire:loading.attr="disabled"
                    wire:target="createProposal,proposalDocument">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M5 13l4 4L19 7"/></svg>
                <span wire:loading.remove wire:target="createProposal,proposalDocument">{{ $editing ? 'Save Revisions' : 'Save Draft Proposal' }}</span>
                <span wire:loading wire:target="createProposal,proposalDocument">{{ $editing ? 'Saving revisions...' : 'Saving draft...' }}</span>
            </button>
        </div>
    </div>
</form>
