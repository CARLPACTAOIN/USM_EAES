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
                    {{ $editing ? 'Update the returned proposal before resubmitting to OSA.' : 'Submit the digital record with the official softcopy and OSA hardcopy status.' }}
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

        <fieldset class="proposal-modal-section"
                  x-data="eventScheduleEditor(
                      $wire.entangle('eventDays'),
                      $wire.entangle('startDate'),
                      $wire.entangle('endDate'),
                      $wire.entangle('sameTimeForAllDays'),
                      @js($errors->getMessages())
                  )">
            <legend class="proposal-section-title">
                <span class="proposal-section-step">2</span>
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
                    </template>
                    @error('eventDays') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="livewire-location-type" class="form-label">Location Type <span class="text-(--color-destructive)">*</span></label>
                    <select id="livewire-location-type" name="location_type" class="form-input form-select @error('locationType') form-input-error @enderror" required wire:model.live="locationType">
                        <option value="on-campus" @selected($locationType === 'on-campus')>On-Campus</option>
                        <option value="off-campus" @selected($locationType === 'off-campus')>Off-Campus</option>
                    </select>
                    @error('locationType') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                </div>
                @if($locationType === 'on-campus')
                    <div>
                        <label for="livewire-venue-id" class="form-label">Confirmed Campus Venue</label>
                        <select id="livewire-venue-id" name="venue_id" class="form-input form-select @error('venueId') form-input-error @enderror" wire:model="venueId">
                            <option value="">Select after external confirmation</option>
                            @foreach($activeVenues as $venue)
                                <option value="{{ $venue->id }}">{{ $venue->name }} — {{ $venue->campus_location }} ({{ $venue->managing_office }})</option>
                            @endforeach
                        </select>
                        <p class="form-helper">Contact the listed managing office outside EAES, then record the confirmation below.</p>
                        @error('venueId') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                    </div>
                @else
                    <div>
                        <label for="livewire-location-details" class="form-label">Off-Campus Venue Name and Address</label>
                        <input type="text" id="livewire-location-details" name="location_details" class="form-input @error('locationDetails') form-input-error @enderror"
                               placeholder="Venue name, street, municipality" value="{{ $locationDetails }}" wire:model="locationDetails">
                        @error('locationDetails') <p class="form-error" role="alert">{{ $message }}</p> @enderror
                    </div>
                @endif
            </div>
        </fieldset>

        <fieldset class="proposal-modal-section">
            <legend class="proposal-section-title">
                <span class="proposal-section-step">3</span>
                Venue confirmation
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

        <fieldset class="proposal-modal-section">
            <legend class="proposal-section-title">
                <span class="proposal-section-step">4</span>
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
                <span class="proposal-section-step">5</span>
                Official PPA packet
            </legend>

            <div class="proposal-grid">
                <div class="proposal-grid-full">
                    <div class="proposal-file-box">
                        <span class="proposal-file-icon" aria-hidden="true">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M12 16v-8m0 0l-3 3m3-3l3 3M4 16.5V18a2 2 0 002 2h12a2 2 0 002-2v-1.5"/></svg>
                        </span>
                        <div>
                            <label for="livewire-proposal-document" class="form-label">Official PPA Proposal Softcopy <span class="text-(--color-text-tertiary)">(student affairs)</span></label>
                            <input type="file" id="livewire-proposal-document" name="proposal_document"
                                   class="form-input @error('proposalDocument') form-input-error @enderror"
                                   accept=".pdf,.doc,.docx,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document"
                                   wire:model="proposalDocument">
                            <p class="form-helper">
                                Accepted: PDF, DOC, DOCX up to 10 MB.
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
                <span class="proposal-section-step">6</span>
                OSA hardcopy status
            </legend>

            <div class="proposal-check-grid">
                <label class="proposal-check-item">
                    <input type="checkbox" name="hardcopy_submitted" value="1" wire:model="hardcopySubmitted" @checked($hardcopySubmitted)>
                    <span>
                        <span class="proposal-check-title">Hardcopy submitted</span>
                        <span class="proposal-check-note">Physical PPA packet received by OSA.</span>
                    </span>
                </label>
                <label class="proposal-check-item">
                    <input type="checkbox" name="head_organization_signed" value="1" wire:model="headOrganizationSigned" @checked($headOrganizationSigned)>
                    <span>
                        <span class="proposal-check-title">Head signed</span>
                        <span class="proposal-check-note">Organization head signature is complete.</span>
                    </span>
                </label>
                <label class="proposal-check-item">
                    <input type="checkbox" name="adviser_signed" value="1" wire:model="adviserSigned" @checked($adviserSigned)>
                    <span>
                        <span class="proposal-check-title">Adviser signed</span>
                        <span class="proposal-check-note">Adviser signature is complete.</span>
                    </span>
                </label>
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
