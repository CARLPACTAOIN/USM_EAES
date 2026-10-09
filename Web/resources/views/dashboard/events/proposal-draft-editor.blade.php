<x-layouts.app :title="'Canonical PPA Packet — ' . ($event->title ?: 'Draft proposal')">
    @php
        $packet = $event->canonicalPpaPacket;
        $specificObjectivesText = is_array($packet?->specific_objectives)
            ? implode("\n", $packet->specific_objectives)
            : ($packet?->specific_objectives ?? '');
        $majorActivitiesText = is_array($packet?->major_activities)
            ? implode("\n", $packet->major_activities)
            : ($packet?->major_activities ?? '');
        $currentLocationType = $event->location_type ?: 'on-campus';
    @endphp

    <div class="space-y-6 pb-16" x-data="ppaPacketWorkspace({
        eventId: @js($event->id),
        saveUrl: @js(route('dashboard.proposal-drafts.save', $event)),
        initialLocationType: @js($currentLocationType),
        initialScore: @js($readiness['score']),
        initialMissingCount: @js($readiness['missing_count']),
        initialIssues: @js($readiness['issues']),
        initialGroupedIssues: @js($readiness['grouped_issues']),
        initialBudgetBreakdown: @js($packet?->budget_breakdown ?? []),
        initialProgramActivities: @js($packet?->program_activities ?? []),
        initialEvaluationQuestions: @js($event->target_demographics['evaluation_questions'] ?? $defaultEvaluationQuestions ?? []),
    })">
        <p class="sr-only" role="alert" aria-live="assertive" x-text="focusedIssue"></p>
        <!-- ─── Page Header ────────────────────────────────────── -->
        <header class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between border-b border-(--color-border) pb-5">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wider text-(--color-accent)">Canonical PPA Packet · Proposal draft</p>
                <h1 class="text-2xl font-bold tracking-tight text-(--color-text-primary)">
                    {{ $event->title ?: 'Untitled Proposal Draft' }}
                </h1>
                <p class="mt-1 text-sm text-(--color-text-secondary)">
                    Prepare the proposal and permit from one guided packet. You can exit at any time; your draft remains available from Events.
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <div class="flex items-center gap-2 px-3 py-1.5 rounded-full border border-(--color-border) bg-(--color-surface-raised) text-xs font-medium" role="status" aria-live="polite">
                    <span class="inline-block w-2 h-2 rounded-full" :class="{
                        'bg-(--color-success)': status === 'Saved just now',
                        'bg-amber-500 animate-pulse': status === 'Saving…' || status === 'Unsaved changes',
                        'bg-red-500': status === 'Could not save' || saveFailed
                    }"></span>
                    <span x-text="status">Saved just now</span>
                    <button x-show="saveFailed" @click="retrySave()" type="button" class="ml-1 font-semibold text-(--color-accent) hover:underline">Retry</button>
                </div>
                <button type="button" @click="flushPendingBefore('{{ route('dashboard.events.proposal-preview', $event) }}')" class="btn btn-secondary text-sm">
                    Proposal preview
                </button>
                <button type="button" @click="flushPendingBefore('{{ route('dashboard.events.permit-preview', $event) }}')" class="btn btn-secondary text-sm">
                    Permit preview
                </button>
                <a href="{{ route('dashboard.events') }}" class="btn btn-secondary text-sm">
                    Exit draft
                </a>
            </div>
        </header>

        <!-- ─── Compact Draft Status Banner ───────────────────── -->
        <section class="card p-4 sm:p-5 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between bg-gradient-to-r from-emerald-950/20 to-transparent border border-emerald-800/30">
            <div class="space-y-1">
                <div class="flex items-center gap-2">
                    <span class="badge badge-success text-xs font-bold uppercase tracking-wider">
                        Draft • {{ $event->title ?: 'Unsaved Draft' }}
                    </span>
                    <span class="text-xs text-(--color-text-tertiary)">
                        SY {{ $event->schoolYear?->label ?? 'Active' }}
                    </span>
                </div>
                <h2 class="text-lg font-semibold text-(--color-text-primary)">One packet, two official views</h2>
                <p class="text-xs sm:text-sm text-(--color-text-secondary)">
                    Work through the packet like a document; validation and previews stay alongside it.
                </p>
            </div>
            <div class="flex items-center gap-4 sm:border-l sm:border-(--color-border) sm:pl-6">
                <div class="text-right">
                    <div class="text-2xl font-black text-(--color-accent)" x-text="score + '%'">{{ $readiness['score'] }}%</div>
                    <p class="text-xs text-(--color-text-secondary)">Authoritative completion</p>
                </div>
                <div class="text-xs text-(--color-text-tertiary) pl-2 border-l border-(--color-border)">
                    <p class="font-medium text-(--color-text-primary)" x-text="missingCount + ' items missing'">{{ $readiness['missing_count'] }} items missing</p>
                    <p x-text="status">Saved just now</p>
                </div>
            </div>
        </section>

        <!-- ─── Horizontal 6-Section Process Rail ─────────────── -->
        <nav class="card p-1 overflow-x-auto" aria-label="Packet sections" role="tablist">
            <div class="flex min-w-[720px] divide-x divide-(--color-border)">
                <button type="button" role="tab" :aria-selected="activeSection === 1" @click="setSection(1)"
                    class="flex-1 px-4 py-3 text-left transition-colors text-xs font-medium focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-(--color-accent)"
                    :class="activeSection === 1 ? 'bg-(--color-surface-raised) text-(--color-accent) font-semibold border-b-2 border-(--color-accent)' : 'text-(--color-text-secondary) hover:text-(--color-text-primary)'">
                    <span class="block text-[10px] uppercase tracking-wider opacity-75">01 Basics</span>
                    <span class="block text-sm font-semibold text-(--color-text-primary) mt-0.5">Basics</span>
                    <span class="text-[11px] text-(--color-text-tertiary)" x-text="groupedIssues.basics && groupedIssues.basics.length ? (groupedIssues.basics.length + ' missing') : 'Complete'">
                        {{ count($readiness['grouped_issues']['basics'] ?? []) ? count($readiness['grouped_issues']['basics']).' missing' : 'Complete' }}
                    </span>
                </button>

                <button type="button" role="tab" :aria-selected="activeSection === 2" @click="setSection(2)"
                    class="flex-1 px-4 py-3 text-left transition-colors text-xs font-medium focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-(--color-accent)"
                    :class="activeSection === 2 ? 'bg-(--color-surface-raised) text-(--color-accent) font-semibold border-b-2 border-(--color-accent)' : 'text-(--color-text-secondary) hover:text-(--color-text-primary)'">
                    <span class="block text-[10px] uppercase tracking-wider opacity-75">02 Schedule</span>
                    <span class="block text-sm font-semibold text-(--color-text-primary) mt-0.5">Schedule &amp; venue</span>
                    <span class="text-[11px] text-(--color-text-tertiary)" x-text="groupedIssues.schedule_and_venue && groupedIssues.schedule_and_venue.length ? (groupedIssues.schedule_and_venue.length + ' missing') : 'Complete'">
                        {{ count($readiness['grouped_issues']['schedule_and_venue'] ?? []) ? count($readiness['grouped_issues']['schedule_and_venue']).' missing' : 'Complete' }}
                    </span>
                </button>

                <button type="button" role="tab" :aria-selected="activeSection === 3" @click="setSection(3)"
                    class="flex-1 px-4 py-3 text-left transition-colors text-xs font-medium focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-(--color-accent)"
                    :class="activeSection === 3 ? 'bg-(--color-surface-raised) text-(--color-accent) font-semibold border-b-2 border-(--color-accent)' : 'text-(--color-text-secondary) hover:text-(--color-text-primary)'">
                    <span class="block text-[10px] uppercase tracking-wider opacity-75">03 Program</span>
                    <span class="block text-sm font-semibold text-(--color-text-primary) mt-0.5">Program &amp; beneficiaries</span>
                    <span class="text-[11px] text-(--color-text-tertiary)" x-text="groupedIssues.program_and_beneficiaries && groupedIssues.program_and_beneficiaries.length ? (groupedIssues.program_and_beneficiaries.length + ' missing') : 'Complete'">
                        {{ count($readiness['grouped_issues']['program_and_beneficiaries'] ?? []) ? count($readiness['grouped_issues']['program_and_beneficiaries']).' missing' : 'Complete' }}
                    </span>
                </button>

                <button type="button" role="tab" :aria-selected="activeSection === 4" @click="setSection(4)"
                    class="flex-1 px-4 py-3 text-left transition-colors text-xs font-medium focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-(--color-accent)"
                    :class="activeSection === 4 ? 'bg-(--color-surface-raised) text-(--color-accent) font-semibold border-b-2 border-(--color-accent)' : 'text-(--color-text-secondary) hover:text-(--color-text-primary)'">
                    <span class="block text-[10px] uppercase tracking-wider opacity-75">04 Budget</span>
                    <span class="block text-sm font-semibold text-(--color-text-primary) mt-0.5">Budget</span>
                    <span class="text-[11px] text-(--color-text-tertiary)" x-text="groupedIssues.budget && groupedIssues.budget.length ? (groupedIssues.budget.length + ' missing') : 'Complete'">
                        {{ count($readiness['grouped_issues']['budget'] ?? []) ? count($readiness['grouped_issues']['budget']).' missing' : 'Complete' }}
                    </span>
                </button>

                <button type="button" role="tab" :aria-selected="activeSection === 5" @click="setSection(5)"
                    class="flex-1 px-4 py-3 text-left transition-colors text-xs font-medium focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-(--color-accent)"
                    :class="activeSection === 5 ? 'bg-(--color-surface-raised) text-(--color-accent) font-semibold border-b-2 border-(--color-accent)' : 'text-(--color-text-secondary) hover:text-(--color-text-primary)'">
                    <span class="block text-[10px] uppercase tracking-wider opacity-75">05 Files</span>
                    <span class="block text-sm font-semibold text-(--color-text-primary) mt-0.5">Evaluation &amp; files</span>
                    <span class="text-[11px] text-(--color-text-tertiary)" x-text="groupedIssues.evaluation_and_supporting_files && groupedIssues.evaluation_and_supporting_files.length ? (groupedIssues.evaluation_and_supporting_files.length + ' missing') : 'Ready'">
                        {{ count($readiness['grouped_issues']['evaluation_and_supporting_files'] ?? []) ? count($readiness['grouped_issues']['evaluation_and_supporting_files']).' missing' : 'Ready' }}
                    </span>
                </button>

                <button type="button" role="tab" :aria-selected="activeSection === 6" @click="setSection(6)"
                    class="flex-1 px-4 py-3 text-left transition-colors text-xs font-medium focus-visible:outline-hidden focus-visible:ring-2 focus-visible:ring-(--color-accent)"
                    :class="activeSection === 6 ? 'bg-(--color-surface-raised) text-(--color-accent) font-semibold border-b-2 border-(--color-accent)' : 'text-(--color-text-secondary) hover:text-(--color-text-primary)'">
                    <span class="block text-[10px] uppercase tracking-wider opacity-75">06 Submit</span>
                    <span class="block text-sm font-semibold text-(--color-text-primary) mt-0.5">Review &amp; submit</span>
                    <span class="text-[11px] font-semibold" :class="missingCount === 0 ? 'text-(--color-success)' : 'text-amber-500'" x-text="missingCount === 0 ? 'Ready' : (missingCount + ' issues')">
                        {{ $readiness['is_submittable'] ? 'Ready' : $readiness['missing_count'] . ' issues' }}
                    </span>
                </button>
            </div>
        </nav>

        <!-- ─── Main Two-Column Document Layout ───────────────── -->
        <div class="grid grid-cols-1 lg:grid-cols-[minmax(0,1fr)_340px] gap-6 items-start">
            <!-- ── Central Document Editor ── -->
            <article class="card p-6 sm:p-8 space-y-8" aria-labelledby="section-heading">

                <!-- ════════ SECTION 1: BASICS ════════ -->
                <section x-show="activeSection === 1" class="space-y-6" aria-labelledby="basics-heading">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wider text-(--color-accent)">Section 1 of 6</p>
                        <h2 id="basics-heading" class="text-xl font-bold text-(--color-text-primary)">Basics</h2>
                        <p class="mt-1 text-sm text-(--color-text-secondary)">Identify the activity, proponent, PPA category, and implementing offices.</p>
                    </div>

                    <!-- Read-Only Prefilled Governance Context -->
                    <div class="rounded-lg border border-(--color-border) bg-(--color-surface-raised) p-4 text-xs sm:text-sm text-(--color-text-secondary) space-y-2">
                        <div class="flex items-center justify-between border-b border-(--color-border) pb-2">
                            <span class="font-semibold text-(--color-text-primary) uppercase text-[11px] tracking-wider">Governance context (prefilled &amp; read-only)</span>
                            <span class="badge badge-neutral text-[10px]">Verified authority</span>
                        </div>
                        <div class="grid sm:grid-cols-2 gap-3 pt-1">
                            <div><strong class="text-(--color-text-primary)">Organizer:</strong> {{ $event->organization_name_snapshot }}</div>
                            <div><strong class="text-(--color-text-primary)">Proponent:</strong> {{ $event->proponent_name_snapshot ?? ($packet?->proponent_name ?: auth()->user()->name) }}</div>
                            <div><strong class="text-(--color-text-primary)">Prepared by:</strong> {{ $packet?->preparedBy?->name ?? auth()->user()->name }}</div>
                            <div><strong class="text-(--color-text-primary)">College / Dept:</strong> {{ $event->college_name_snapshot }} {{ $event->department_name_snapshot ? '• '.$event->department_name_snapshot : '' }}</div>
                        </div>
                    </div>

                    <!-- Editable Fields -->
                    <div class="grid gap-5 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <label for="draft-title" class="form-label">
                                PPA title <span class="text-red-500">*</span>
                            </label>
                            <input id="draft-title" name="title" type="text" class="form-input" value="{{ $event->title }}"
                                placeholder="e.g. 2026 Leadership Development Summit"
                                @input="queueSave('title', $event.target.value)" @change="queueSave('title', $event.target.value)">
                            <p class="text-xs text-(--color-text-tertiary) mt-1">Official event or activity title as stated in university communications.</p>
                        </div>

                        <div>
                            <label for="draft-category" class="form-label">
                                PPA category <span class="text-red-500">*</span>
                            </label>
                            <select id="draft-category" name="proposal_category" class="form-input form-select"
                                @change="queueSave('proposal_category', $event.target.value)">
                                <option value="">Select category</option>
                                @foreach(['program' => 'Program', 'project' => 'Project', 'activity' => 'Activity'] as $value => $label)
                                    <option value="{{ $value }}" @selected($event->proposal_category === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <label for="draft-proponent-position" class="form-label">
                                Proponent position <span class="text-red-500">*</span>
                            </label>
                            <input id="draft-proponent-position" name="proponent_position" type="text" class="form-input"
                                value="{{ $packet?->proponent_position }}" placeholder="e.g. Secretary / Project Head"
                                @input="queueSave('canonical_packet', { proponent_position: $event.target.value })"
                                @change="queueSave('canonical_packet', { proponent_position: $event.target.value })">
                        </div>

                        <div>
                            <label for="draft-proponent-contact" class="form-label">
                                Proponent contact number <span class="text-red-500">*</span>
                            </label>
                            <input id="draft-proponent-contact" name="proponent_contact_number" type="text" class="form-input"
                                value="{{ $packet?->proponent_contact_number }}" placeholder="e.g. 0917-123-4567"
                                @input="queueSave('canonical_packet', { proponent_contact_number: $event.target.value })"
                                @change="queueSave('canonical_packet', { proponent_contact_number: $event.target.value })">
                        </div>

                        <div>
                            <label for="draft-implementing-office" class="form-label">
                                Implementing office <span class="text-red-500">*</span>
                            </label>
                            <input id="draft-implementing-office" name="implementing_office" type="text" class="form-input"
                                value="{{ $packet?->implementing_office }}" placeholder="e.g. Committee on Student Affairs"
                                @input="queueSave('canonical_packet', { implementing_office: $event.target.value })"
                                @change="queueSave('canonical_packet', { implementing_office: $event.target.value })">
                        </div>

                        <div class="sm:col-span-2">
                            <label for="draft-collaborating-office" class="form-label">Collaborating office (optional)</label>
                            <input id="draft-collaborating-office" name="collaborating_office" type="text" class="form-input"
                                value="{{ $packet?->collaborating_office }}" placeholder="e.g. Department of Information Systems"
                                @input="queueSave('canonical_packet', { collaborating_office: $event.target.value })"
                                @change="queueSave('canonical_packet', { collaborating_office: $event.target.value })">
                        </div>
                    </div>
                </section>

                <!-- ════════ SECTION 2: SCHEDULE AND VENUE ════════ -->
                <section x-show="activeSection === 2" class="space-y-6" aria-labelledby="schedule-heading">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wider text-(--color-accent)">Section 2 of 6</p>
                        <h2 id="schedule-heading" class="text-xl font-bold text-(--color-text-primary)">Schedule and venue</h2>
                        <p class="mt-1 text-sm text-(--color-text-secondary)">Set when and where the activity will happen. Venue requirements adapt automatically to location type.</p>
                    </div>

                    <div class="grid gap-5 sm:grid-cols-2">
                        <div>
                            <label for="draft-start-date" class="form-label">
                                Activity start date <span class="text-red-500">*</span>
                            </label>
                            <input id="draft-start-date" name="start_date" type="date" class="form-input"
                                value="{{ $event->start_date?->toDateString() }}"
                                @input="queueSave('start_date', $event.target.value)" @change="queueSave('start_date', $event.target.value)">
                        </div>

                        <div>
                            <label for="draft-end-date" class="form-label">
                                Activity end date <span class="text-red-500">*</span>
                            </label>
                            <input id="draft-end-date" name="end_date" type="date" class="form-input"
                                value="{{ $event->end_date?->toDateString() }}"
                                @input="queueSave('end_date', $event.target.value)" @change="queueSave('end_date', $event.target.value)">
                        </div>

                        <div class="sm:col-span-2">
                            <label for="draft-location-type" class="form-label">
                                Where will this activity happen? <span class="text-red-500">*</span>
                            </label>
                            <select id="draft-location-type" name="location_type" class="form-input form-select"
                                x-model="locationType"
                                @change="queueSave('location_type', $event.target.value)">
                                <option value="on-campus">On-campus (University venue catalog)</option>
                                <option value="off-campus">Off-campus (External venue with confirmation)</option>
                                <option value="online">Online (Virtual platform / webinar)</option>
                            </select>
                        </div>
                    </div>

                    <!-- On-Campus Branch -->
                    <div x-show="locationType === 'on-campus'" class="space-y-4 rounded-lg border border-(--color-border) p-5 bg-(--color-surface-raised)">
                        <h3 class="text-sm font-semibold text-(--color-text-primary)">On-campus venue</h3>
                        <p class="text-xs text-(--color-text-secondary)">Select an active venue from the university catalog or provide campus location details.</p>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="sm:col-span-2">
                                <label for="draft-venue-id" class="form-label">Catalog venue</label>
                                <select id="draft-venue-id" name="venue_id" class="form-input form-select"
                                    @change="queueSave('venue_id', $event.target.value)">
                                    <option value="">Select a campus venue</option>
                                    @foreach($venues as $venue)
                                        <option value="{{ $venue->id }}" @selected($event->venue_id === $venue->id)>
                                            {{ $venue->displayName() }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="sm:col-span-2">
                                <label for="draft-location-details" class="form-label">Campus specific room / hall notes</label>
                                <input id="draft-location-details" name="location_details" type="text" class="form-input"
                                    value="{{ $event->location_details }}" placeholder="e.g. Room 204, IT Building"
                                    @input="queueSave('location_details', $event.target.value)" @change="queueSave('location_details', $event.target.value)">
                            </div>
                        </div>
                    </div>

                    <!-- Off-Campus Branch -->
                    <div x-show="locationType === 'off-campus'" class="space-y-4 rounded-lg border border-amber-800/40 p-5 bg-amber-950/10">
                        <div class="flex items-center justify-between">
                            <h3 class="text-sm font-semibold text-amber-300">External venue confirmation requirements</h3>
                            <span class="badge badge-warning text-[10px]">Off-campus required</span>
                        </div>
                        <p class="text-xs text-(--color-text-secondary)">
                            Off-campus proposals require confirming office records, date, and private confirmation evidence.
                        </p>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="sm:col-span-2">
                                <label for="draft-location-details" class="form-label">
                                    Off-campus venue name and complete address <span class="text-red-500">*</span>
                                </label>
                                <input id="draft-location-details" name="location_details" type="text" class="form-input"
                                    value="{{ $event->location_details }}" placeholder="e.g. Southpoint Convention Center, Kabacan, Cotabato"
                                    @input="queueSave('location_details', $event.target.value)" @change="queueSave('location_details', $event.target.value)">
                            </div>

                            <div>
                                <label for="draft-confirmation-office" class="form-label">
                                    Confirming external office / authority <span class="text-red-500">*</span>
                                </label>
                                <input id="draft-confirmation-office" name="venue_confirmation_office" type="text" class="form-input"
                                    value="{{ $event->venue_confirmation_office }}" placeholder="e.g. Municipal Tourism Office"
                                    @input="queueSave('venue_confirmation_office', $event.target.value)" @change="queueSave('venue_confirmation_office', $event.target.value)">
                            </div>

                            <div>
                                <label for="draft-confirmation-date" class="form-label">
                                    Confirmation date <span class="text-red-500">*</span>
                                </label>
                                <input id="draft-confirmation-date" name="venue_confirmation_date" type="date" class="form-input"
                                    value="{{ $event->venue_confirmation_date?->toDateString() }}"
                                    @input="queueSave('venue_confirmation_date', $event.target.value)" @change="queueSave('venue_confirmation_date', $event.target.value)">
                            </div>

                            <div class="sm:col-span-2">
                                <label for="draft-confirmation-reference" class="form-label">Confirmation reference number (optional)</label>
                                <input id="draft-confirmation-reference" name="venue_confirmation_reference_number" type="text" class="form-input"
                                    value="{{ $event->venue_confirmation_reference_number }}" placeholder="e.g. MTO-2026-CONF-044"
                                    @input="queueSave('venue_confirmation_reference_number', $event.target.value)" @change="queueSave('venue_confirmation_reference_number', $event.target.value)">
                            </div>

                            <div class="sm:col-span-2 space-y-2">
                                <label for="draft-venue-evidence" class="form-label">
                                    Private venue confirmation evidence <span class="text-red-500">*</span>
                                </label>
                                @if($event->venue_confirmation_document_path)
                                    <div class="flex items-center justify-between p-3 rounded-lg border border-emerald-800/40 bg-emerald-950/20 text-xs">
                                        <div class="flex items-center gap-2">
                                            <span class="text-(--color-success)">✓ Attached:</span>
                                            <span class="font-medium text-(--color-text-primary)">{{ $event->venue_confirmation_document_original_name ?: 'Venue evidence' }}</span>
                                        </div>
                                        <div class="flex items-center gap-2">
                                            <a href="{{ route('dashboard.events.venue-evidence', $event) }}" target="_blank" class="text-(--color-accent) hover:underline">Download</a>
                                            <button type="button" @click="removeFile('clear_venue_confirmation_document')" class="text-red-400 hover:underline">Remove</button>
                                        </div>
                                    </div>
                                @endif
                                <input id="draft-venue-evidence" type="file" class="form-input"
                                    accept=".pdf,.doc,.docx,.jpg,.jpeg,.png"
                                    @change="uploadFile($event.target, 'venue_confirmation_document')">
                                <p class="text-xs text-(--color-text-tertiary)">Accepted: PDF, DOC, DOCX, JPG, JPEG, PNG up to 10 MB. Evidence remains private to university approvers.</p>
                            </div>
                        </div>
                    </div>

                    <!-- Online Branch -->
                    <div x-show="locationType === 'online'" class="space-y-4 rounded-lg border border-(--color-border) p-5 bg-(--color-surface-raised)">
                        <h3 class="text-sm font-semibold text-(--color-text-primary)">Online location</h3>
                        <p class="text-xs text-(--color-text-secondary)">Provide platform and meeting connection details for virtual activities.</p>

                        <div>
                            <label for="draft-location-details" class="form-label">
                                Platform or meeting link <span class="text-red-500">*</span>
                            </label>
                            <input id="draft-location-details" name="location_details" type="text" class="form-input"
                                value="{{ $event->location_details }}" placeholder="e.g. Zoom (Meeting ID: 123 456 7890) / Google Meet"
                                @input="queueSave('location_details', $event.target.value)" @change="queueSave('location_details', $event.target.value)">
                        </div>
                    </div>
                </section>

                <!-- ════════ SECTION 3: PROGRAM AND BENEFICIARIES ════════ -->
                <section x-show="activeSection === 3" class="space-y-6" aria-labelledby="program-heading">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wider text-(--color-accent)">Section 3 of 6</p>
                        <h2 id="program-heading" class="text-xl font-bold text-(--color-text-primary)">Program and beneficiaries</h2>
                        <p class="mt-1 text-sm text-(--color-text-secondary)">Describe the rationale, objectives, methodology, program flow, and intended beneficiaries.</p>
                    </div>

                    <div class="space-y-5">
                        <div>
                            <label for="draft-rationale" class="form-label">
                                Rationale / Significance <span class="text-red-500">*</span>
                            </label>
                            <textarea id="draft-rationale" rows="3" class="form-input"
                                placeholder="Why is this activity being conducted?"
                                @input="queueSave('canonical_packet', { rationale: $event.target.value })"
                                @change="queueSave('canonical_packet', { rationale: $event.target.value })">{{ $packet?->rationale }}</textarea>
                        </div>

                        <div>
                            <label for="draft-general-objective" class="form-label">
                                General objective <span class="text-red-500">*</span>
                            </label>
                            <textarea id="draft-general-objective" rows="2" class="form-input"
                                placeholder="Primary overall objective of the program"
                                @input="queueSave('canonical_packet', { general_objective: $event.target.value })"
                                @change="queueSave('canonical_packet', { general_objective: $event.target.value })">{{ $packet?->general_objective }}</textarea>
                        </div>

                        <div>
                            <label for="draft-specific-objectives" class="form-label">
                                Specific objectives <span class="text-red-500">*</span>
                            </label>
                            <textarea id="draft-specific-objectives" rows="3" class="form-input"
                                placeholder="Enter one specific objective per line"
                                @input="queueSave('canonical_packet', { specific_objectives: $event.target.value })"
                                @change="queueSave('canonical_packet', { specific_objectives: $event.target.value })">{{ $specificObjectivesText }}</textarea>
                            <p class="text-xs text-(--color-text-tertiary) mt-1">Separate each objective on a new line.</p>
                        </div>

                        <div>
                            <label for="draft-methodology" class="form-label">
                                Methodology / Mechanics and guidelines <span class="text-red-500">*</span>
                            </label>
                            <textarea id="draft-methodology" rows="3" class="form-input"
                                placeholder="How will the activity be carried out?"
                                @input="queueSave('canonical_packet', { methodology: $event.target.value })"
                                @change="queueSave('canonical_packet', { methodology: $event.target.value })">{{ $packet?->methodology }}</textarea>
                        </div>

                        <!-- Activities (Adaptive: Program activities table if 'activity', Major activities list otherwise) -->
                        <div id="draft-activities" tabindex="-1">
                            @if($event->proposal_category === 'activity')
                                <div class="space-y-3">
                                    <div class="flex items-center justify-between">
                                        <label class="form-label mb-0">Program of activities <span class="text-red-500">*</span></label>
                                        <button type="button" @click="addActivityRow()" class="btn btn-secondary text-xs py-1 px-2.5">+ Add time slot</button>
                                    </div>
                                    <div class="table-responsive border border-(--color-border) rounded-lg">
                                        <table class="data-table text-xs">
                                            <thead>
                                                <tr>
                                                    <th>Time</th>
                                                    <th>Activity / Topic</th>
                                                    <th>Person in charge</th>
                                                    <th class="w-12 text-center">Action</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <template x-for="(row, index) in programActivities" :key="index">
                                                    <tr>
                                                        <td>
                                                            <input type="text" x-model="row.time" @input="saveProgramActivities()" class="form-input py-1 text-xs" placeholder="08:00 AM">
                                                        </td>
                                                        <td>
                                                            <input type="text" x-model="row.activity" @input="saveProgramActivities()" class="form-input py-1 text-xs" placeholder="Opening Ceremony">
                                                        </td>
                                                        <td>
                                                            <input type="text" x-model="row.person_in_charge" @input="saveProgramActivities()" class="form-input py-1 text-xs" placeholder="Program Head">
                                                        </td>
                                                        <td class="text-center">
                                                            <button type="button" @click="removeActivityRow(index)" class="text-red-400 hover:text-red-300 font-bold">×</button>
                                                        </td>
                                                    </tr>
                                                </template>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            @else
                                <div>
                                    <label class="form-label">Major activities <span class="text-red-500">*</span></label>
                                    <textarea rows="3" class="form-input"
                                        placeholder="Enter major activity milestones (one per line)"
                                        @input="queueSave('canonical_packet', { major_activities: $event.target.value })"
                                        @change="queueSave('canonical_packet', { major_activities: $event.target.value })">{{ $majorActivitiesText }}</textarea>
                                    <p class="text-xs text-(--color-text-tertiary) mt-1">One major activity milestone per line.</p>
                                </div>
                            @endif
                        </div>

                        <div>
                            <label for="draft-expected-output" class="form-label">
                                Expected output <span class="text-red-500">*</span>
                            </label>
                            <textarea id="draft-expected-output" rows="2" class="form-input"
                                placeholder="Tangible outcomes and outputs"
                                @input="queueSave('canonical_packet', { expected_output: $event.target.value })"
                                @change="queueSave('canonical_packet', { expected_output: $event.target.value })">{{ $packet?->expected_output }}</textarea>
                        </div>

                        <div class="grid gap-5 sm:grid-cols-2">
                            <div>
                                <label for="draft-beneficiaries" class="form-label">
                                    Target beneficiaries / participants <span class="text-red-500">*</span>
                                </label>
                                <input id="draft-beneficiaries" type="text" class="form-input"
                                    value="{{ $packet?->beneficiaries }}" placeholder="e.g. All BS Information Systems students"
                                    @input="queueSave('canonical_packet', { beneficiaries: $event.target.value })"
                                    @change="queueSave('canonical_packet', { beneficiaries: $event.target.value })">
                            </div>

                            <div>
                                <label for="draft-delegate-count" class="form-label">Estimated delegate / participant count</label>
                                <input id="draft-delegate-count" type="number" min="0" class="form-input"
                                    value="{{ $packet?->delegate_count }}" placeholder="e.g. 150"
                                    @input="queueSave('canonical_packet', { delegate_count: $event.target.value })"
                                    @change="queueSave('canonical_packet', { delegate_count: $event.target.value })">
                            </div>
                        </div>
                    </div>
                </section>

                <!-- ════════ SECTION 4: BUDGET ════════ -->
                <section x-show="activeSection === 4" class="space-y-6" aria-labelledby="budget-heading">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wider text-(--color-accent)">Section 4 of 6</p>
                        <h2 id="budget-heading" class="text-xl font-bold text-(--color-text-primary)">Budget</h2>
                        <p class="mt-1 text-sm text-(--color-text-secondary)">Record funding sources and an itemized expense plan.</p>
                    </div>

                    <div class="grid gap-5 sm:grid-cols-2">
                        <div>
                            <label for="draft-source-of-fund" class="form-label">
                                Source of fund <span class="text-red-500">*</span>
                            </label>
                            <input id="draft-source-of-fund" type="text" class="form-input"
                                value="{{ $packet?->source_of_fund }}" placeholder="e.g. Organization Funds / Registration Fee"
                                @input="queueSave('canonical_packet', { source_of_fund: $event.target.value })"
                                @change="queueSave('canonical_packet', { source_of_fund: $event.target.value })">
                        </div>

                        <div>
                            <label for="draft-budget-cost" class="form-label">
                                Total estimated budget cost (PHP) <span class="text-red-500">*</span>
                            </label>
                            <input id="draft-budget-cost" type="number" step="0.01" min="0" class="form-input"
                                value="{{ $packet?->budget_cost }}" placeholder="0.00"
                                @input="queueSave('canonical_packet', { budget_cost: $event.target.value })"
                                @change="queueSave('canonical_packet', { budget_cost: $event.target.value })">
                        </div>

                        <div class="sm:col-span-2">
                            <label for="draft-resolution-number" class="form-label">Organization resolution number (optional)</label>
                            <input id="draft-resolution-number" type="text" class="form-input"
                                value="{{ $event->resolution_number }}" placeholder="e.g. RES-2026-004"
                                @input="queueSave('resolution_number', $event.target.value)"
                                @change="queueSave('resolution_number', $event.target.value)">
                        </div>
                    </div>

                    <!-- Itemized Budget Breakdown Table -->
                    <div id="draft-budget-breakdown" tabindex="-1" class="space-y-3 pt-2">
                        <div class="flex items-center justify-between">
                            <label class="form-label mb-0">Itemized budget breakdown <span class="text-red-500">*</span></label>
                            <button type="button" @click="addBudgetRow()" class="btn btn-secondary text-xs py-1 px-2.5">+ Add expense item</button>
                        </div>
                        <div class="table-responsive border border-(--color-border) rounded-lg">
                            <table class="data-table text-xs">
                                <thead>
                                    <tr>
                                        <th>Item description</th>
                                        <th class="w-36">Amount (PHP)</th>
                                        <th>Notes / remarks</th>
                                        <th class="w-12 text-center">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <template x-for="(row, index) in budgetBreakdown" :key="index">
                                        <tr>
                                            <td>
                                                <input type="text" x-model="row.item" @input="saveBudgetBreakdown()" class="form-input py-1 text-xs" placeholder="e.g. Certificates & Medals">
                                            </td>
                                            <td>
                                                <input type="number" step="0.01" min="0" x-model="row.amount" @input="saveBudgetBreakdown()" class="form-input py-1 text-xs" placeholder="0.00">
                                            </td>
                                            <td>
                                                <input type="text" x-model="row.notes" @input="saveBudgetBreakdown()" class="form-input py-1 text-xs" placeholder="Optional notes">
                                            </td>
                                            <td class="text-center">
                                                <button type="button" @click="removeBudgetRow(index)" class="text-red-400 hover:text-red-300 font-bold">×</button>
                                            </td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </section>

                <!-- ════════ SECTION 5: EVALUATION AND SUPPORTING FILES ════════ -->
                <section x-show="activeSection === 5" class="space-y-6" aria-labelledby="files-heading">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wider text-(--color-accent)">Section 5 of 6</p>
                        <h2 id="files-heading" class="text-xl font-bold text-(--color-text-primary)">Evaluation and supporting files</h2>
                        <p class="mt-1 text-sm text-(--color-text-secondary)">Review evaluation questions and attach only the evidence required for this packet.</p>
                    </div>

                    <!-- Evaluation Questions Callout -->
                    <div id="draft-evaluation-questions" class="rounded-lg border border-(--color-border) bg-(--color-surface-raised) p-4 space-y-3">
                        <div class="flex items-center justify-between">
                            <h3 class="text-sm font-semibold text-(--color-text-primary)">Activity Evaluation Form</h3>
                            <span class="badge badge-success text-[10px]">Standard 1-5 Likert scale</span>
                        </div>
                        <p class="text-xs text-(--color-text-secondary)">
                            Every attendee completes this evaluation to validate attendance. Standard university survey dimensions are pre-configured:
                        </p>
                        <ul class="text-xs text-(--color-text-secondary) space-y-1.5 list-disc pl-4">
                            <li><strong>Attainment of objectives:</strong> Were the stated objectives of the activity met?</li>
                            <li><strong>Speaker or facilitator mastery:</strong> How well did the speaker deliver the content?</li>
                            <li><strong>Venue and comfort:</strong> Was the venue appropriate, comfortable, and well-prepared?</li>
                        </ul>
                    </div>

                    <!-- Supporting Documents -->
                    <div class="space-y-5 pt-2">
                        <!-- External Proposal Document (Softcopy) -->
                        <div id="draft-proposal-document" class="space-y-2 rounded-lg border border-(--color-border) p-4">
                            <div class="flex items-center justify-between">
                                <label for="input-proposal-document" class="form-label mb-0">
                                    External proposal document (optional supporting evidence)
                                </label>
                                <span class="badge badge-neutral text-[10px]">Optional</span>
                            </div>
                            <p class="text-xs text-(--color-text-secondary)">
                                The Canonical PPA Packet is the official source of truth. An external proposal document remains optional supporting evidence.
                            </p>
                            @if($event->proposal_document_path)
                                <div class="flex items-center justify-between p-3 rounded-lg border border-emerald-800/40 bg-emerald-950/20 text-xs">
                                    <div class="flex items-center gap-2">
                                        <span class="text-(--color-success)">✓ Attached:</span>
                                        <span class="font-medium text-(--color-text-primary)">{{ $event->proposal_document_original_name ?: 'Proposal softcopy' }}</span>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <a href="{{ route('dashboard.events.proposal-document', $event) }}" target="_blank" class="text-(--color-accent) hover:underline">Download</a>
                                        <button type="button" @click="removeFile('clear_proposal_document')" class="text-red-400 hover:underline">Remove</button>
                                    </div>
                                </div>
                            @endif
                            <input id="input-proposal-document" type="file" class="form-input"
                                accept=".pdf,.doc,.docx,.jpg,.jpeg,.png"
                                @change="uploadFile($event.target, 'proposal_document')">
                            <p class="text-xs text-(--color-text-tertiary)">PDF, DOC, DOCX, JPG, JPEG, or PNG up to 10 MB.</p>
                        </div>

                        <!-- Supporting Checklist Evidence -->
                        <div id="draft-supporting-document" class="space-y-2 rounded-lg border border-(--color-border) p-4">
                            <div class="flex items-center justify-between">
                                <label for="input-supporting-document" class="form-label mb-0">
                                    Additional supporting evidence / checklist file (optional)
                                </label>
                                <span class="badge badge-neutral text-[10px]">Optional</span>
                            </div>
                            @if($packet?->supporting_document_path)
                                <div class="flex items-center justify-between p-3 rounded-lg border border-emerald-800/40 bg-emerald-950/20 text-xs">
                                    <div class="flex items-center gap-2">
                                        <span class="text-(--color-success)">✓ Attached:</span>
                                        <span class="font-medium text-(--color-text-primary)">{{ $packet->supporting_document_original_name ?: 'Supporting document' }}</span>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <button type="button" @click="removeFile('clear_supporting_document')" class="text-red-400 hover:underline">Remove</button>
                                    </div>
                                </div>
                            @endif
                            <input id="input-supporting-document" type="file" class="form-input"
                                accept=".pdf,.doc,.docx,.jpg,.jpeg,.png"
                                @change="uploadFile($event.target, 'supporting_document')">
                            <p class="text-xs text-(--color-text-tertiary)">Any additional approvals, budget quotations, or invitation endorsements.</p>
                        </div>
                    </div>
                </section>

                <!-- ════════ SECTION 6: REVIEW AND SUBMIT ════════ -->
                <section x-show="activeSection === 6" class="space-y-6" aria-labelledby="review-heading">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wider text-(--color-accent)">Section 6 of 6</p>
                        <h2 id="review-heading" class="text-xl font-bold text-(--color-text-primary)">Review and submit</h2>
                        <p class="mt-1 text-sm text-(--color-text-secondary)">Review your completion score, resolve linked items, and confirm workflow binding.</p>
                    </div>

                    <!-- Authoritative Readiness Card -->
                    <div class="p-5 rounded-lg border border-(--color-border) bg-(--color-surface-raised) space-y-4">
                        <div class="flex items-center justify-between">
                            <h3 class="text-base font-semibold text-(--color-text-primary)">Authoritative Readiness</h3>
                            <span class="text-2xl font-black text-(--color-accent)" x-text="score + '%'">{{ $readiness['score'] }}%</span>
                        </div>

                        <div x-show="missingCount > 0" class="space-y-3">
                            <p class="text-xs font-semibold text-amber-400 uppercase tracking-wider">
                                <span x-text="missingCount"></span> items must be resolved before submission:
                            </p>
                            <div class="space-y-2">
                                <template x-for="(issue, idx) in issues" :key="idx">
                                    <div class="flex items-center justify-between p-2.5 rounded bg-black/20 border border-(--color-border) text-xs">
                                        <div class="space-y-0.5">
                                            <span class="font-semibold text-(--color-text-primary)" x-text="issue.label"></span>
                                            <p class="text-(--color-text-tertiary)" x-text="issue.message"></p>
                                        </div>
                                    <button type="button" @click="jumpTo(issue)" :aria-label="'Fix ' + issue.label + ': ' + issue.message" class="text-xs text-(--color-accent) font-semibold hover:underline shrink-0 ml-3">
                                            Fix in <span x-text="issue.section_title"></span> →
                                        </button>
                                    </div>
                                </template>
                            </div>
                        </div>

                        <div x-show="missingCount === 0" class="p-4 rounded bg-emerald-950/20 border border-emerald-800/40 text-xs text-(--color-success) flex items-center gap-2">
                            <span class="text-base">✓</span>
                            <span>All mandatory Canonical PPA Packet requirements have been satisfied. Ready for submission.</span>
                        </div>
                    </div>

                    <!-- Workflow Binding Confirmation Notice -->
                    <div class="rounded-lg border border-(--color-border) p-4 bg-(--color-surface-raised) text-xs text-(--color-text-secondary) space-y-2">
                        <h4 class="font-semibold text-(--color-text-primary) text-sm">Workflow Binding Notice</h4>
                        <p>
                            Submitting creates one <strong>Workflow Run</strong> bound to the active applicable <strong>Published Template Version</strong>. Later template edits will not change this proposal's workflow.
                        </p>
                    </div>

                    <!-- Submit Action Button -->
                    <div class="pt-2">
                        <form method="POST" action="{{ route('dashboard.events.submit', $event) }}" @submit.prevent="submitAfterFlushing($event)">
                            @csrf
                            <button type="submit" class="btn btn-primary w-full py-3 text-base font-semibold"
                                :disabled="missingCount > 0">
                                <span x-show="missingCount > 0">Resolve missing requirements before submission</span>
                                <span x-show="missingCount === 0">Confirm and submit Canonical PPA Packet</span>
                            </button>
                        </form>
                    </div>
                </section>

                <!-- ════════ SECTION FOOTER CONTROLS ════════ -->
                <div class="flex items-center justify-between pt-6 border-t border-(--color-border)">
                    <button type="button" @click="setSection(activeSection - 1)" :disabled="activeSection === 1"
                        class="btn btn-secondary text-xs sm:text-sm" :class="{ 'opacity-50 cursor-not-allowed': activeSection === 1 }">
                        ← Previous section
                    </button>
                    <span class="text-xs font-medium text-(--color-text-tertiary)" x-text="status" aria-live="polite">Saved just now</span>
                    <button type="button" @click="setSection(activeSection + 1)" :disabled="activeSection === 6"
                        class="btn btn-primary text-xs sm:text-sm" :class="{ 'opacity-50 cursor-not-allowed': activeSection === 6 }">
                        Next section →
                    </button>
                </div>
            </article>

            <!-- ── Right Column: Persistent Review Drawer ── -->
            <aside class="card divide-y divide-(--color-border) sticky top-6 self-start text-xs" aria-label="Review drawer">
                <!-- Drawer Section 1: Packet State -->
                <div class="p-4 space-y-3">
                    <h3 class="font-bold text-sm text-(--color-text-primary)">Packet state</h3>
                    <div class="space-y-2 text-xs">
                        <div class="flex justify-between items-center">
                            <span class="text-(--color-text-secondary)">Authoritative completion</span>
                            <strong class="text-(--color-accent) font-black text-sm" x-text="score + '%'">{{ $readiness['score'] }}%</strong>
                        </div>
                        <div class="flex justify-between items-center">
                            <span class="text-(--color-text-secondary)">Location branch</span>
                            <strong class="font-semibold text-(--color-text-primary) capitalize" x-text="locationType.replace('-', ' ')">
                                {{ str_replace('-', ' ', $currentLocationType) }}
                            </strong>
                        </div>
                        <div class="flex justify-between items-center">
                            <span class="text-(--color-text-secondary)">Missing items</span>
                            <strong :class="missingCount > 0 ? 'text-amber-500' : 'text-(--color-success)'" x-text="missingCount + ' items'">
                                {{ $readiness['missing_count'] }} items
                            </strong>
                        </div>
                    </div>
                </div>

                <!-- Drawer Section 2: Needs Attention -->
                <div class="p-4 space-y-3">
                    <h3 class="font-bold text-sm text-(--color-text-primary)">Needs attention</h3>

                    <template x-if="missingCount === 0">
                        <p class="text-xs text-(--color-success)">✓ All requirements complete</p>
                    </template>

                    <div class="space-y-3" x-show="missingCount > 0">
                        <!-- Group: Basics -->
                        <div x-show="groupedIssues.basics && groupedIssues.basics.length" class="space-y-1">
                            <h4 class="text-[10px] font-bold uppercase tracking-wider text-(--color-text-tertiary)">Basics</h4>
                            <template x-for="(item, i) in (groupedIssues.basics || [])" :key="i">
                                <button type="button" @click="jumpTo(item)" :aria-label="'Fix ' + item.label + ': ' + item.message"
                                    class="block w-full text-left py-1 text-xs text-(--color-accent) hover:underline truncate">
                                    • <span x-text="item.label"></span>
                                </button>
                            </template>
                        </div>

                        <!-- Group: Schedule & Venue -->
                        <div x-show="groupedIssues.schedule_and_venue && groupedIssues.schedule_and_venue.length" class="space-y-1">
                            <h4 class="text-[10px] font-bold uppercase tracking-wider text-(--color-text-tertiary)">Schedule &amp; venue</h4>
                            <template x-for="(item, i) in (groupedIssues.schedule_and_venue || [])" :key="i">
                                <button type="button" @click="jumpTo(item)" :aria-label="'Fix ' + item.label + ': ' + item.message"
                                    class="block w-full text-left py-1 text-xs text-(--color-accent) hover:underline truncate">
                                    • <span x-text="item.label"></span>
                                </button>
                            </template>
                        </div>

                        <!-- Group: Program & Beneficiaries -->
                        <div x-show="groupedIssues.program_and_beneficiaries && groupedIssues.program_and_beneficiaries.length" class="space-y-1">
                            <h4 class="text-[10px] font-bold uppercase tracking-wider text-(--color-text-tertiary)">Program &amp; beneficiaries</h4>
                            <template x-for="(item, i) in (groupedIssues.program_and_beneficiaries || [])" :key="i">
                                <button type="button" @click="jumpTo(item)" :aria-label="'Fix ' + item.label + ': ' + item.message"
                                    class="block w-full text-left py-1 text-xs text-(--color-accent) hover:underline truncate">
                                    • <span x-text="item.label"></span>
                                </button>
                            </template>
                        </div>

                        <!-- Group: Budget -->
                        <div x-show="groupedIssues.budget && groupedIssues.budget.length" class="space-y-1">
                            <h4 class="text-[10px] font-bold uppercase tracking-wider text-(--color-text-tertiary)">Budget</h4>
                            <template x-for="(item, i) in (groupedIssues.budget || [])" :key="i">
                                <button type="button" @click="jumpTo(item)" :aria-label="'Fix ' + item.label + ': ' + item.message"
                                    class="block w-full text-left py-1 text-xs text-(--color-accent) hover:underline truncate">
                                    • <span x-text="item.label"></span>
                                </button>
                            </template>
                        </div>
                    </div>
                </div>

                <!-- Drawer Section 3: Generated Views -->
                <div class="p-4 space-y-2">
                    <h3 class="font-bold text-sm text-(--color-text-primary)">Generated views</h3>
                    <div class="grid gap-2">
                        <button type="button" @click="flushPendingBefore('{{ route('dashboard.events.proposal-preview', $event) }}')"
                            class="btn btn-secondary text-xs w-full justify-center">
                            Open proposal preview
                        </button>
                        <button type="button" @click="flushPendingBefore('{{ route('dashboard.events.permit-preview', $event) }}')"
                            class="btn btn-secondary text-xs w-full justify-center">
                            Open permit preview
                        </button>
                    </div>
                </div>

                <!-- Drawer Section 4: Workflow Binding & Submit Navigation -->
                <div class="p-4 space-y-3">
                    <div class="rounded bg-black/20 p-2.5 text-[11px] text-(--color-text-secondary) border border-(--color-border)">
                        Submitting binds the active Published Template Version to one Workflow Run. Later template edits will not change it.
                    </div>
                    <button type="button" @click="setSection(6)" class="btn btn-primary w-full text-xs font-semibold justify-center">
                        Review submission
                    </button>
                </div>
            </aside>
        </div>
    </div>
</x-layouts.app>
