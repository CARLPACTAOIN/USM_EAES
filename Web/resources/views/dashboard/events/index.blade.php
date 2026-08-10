<x-layouts.app :title="'Event Proposals — EAES'">
    @livewire('dashboard.events-workspace', [
        'workspace' => request('workspace', 'organized'),
        'status' => request('status', ''),
        'search' => request('q', ''),
        'perPage' => (int) request('per_page', 15),
    ])

    @if($canCreateProposals)
    {{-- Create Event Modal (HTML dialog) --}}
    <dialog id="create-event-modal"
            class="proposal-modal"
            aria-labelledby="create-event-modal-title"
            aria-describedby="create-event-modal-description"
            onclick="if (event.target === this) this.close()">
        @livewire('dashboard.event-proposal-create')
    </dialog>
    @endif

    @if($user->hasRole('Super Admin (OSA)'))
    {{-- Proposal Review Details Modal --}}
    <dialog id="proposal-detail-modal"
            class="proposal-modal modal-md"
            aria-labelledby="proposal-detail-modal-title">
        <div class="proposal-modal-form">
            <div class="proposal-modal-header">
                <div class="proposal-modal-title-row">
                    <span class="proposal-modal-icon" aria-hidden="true">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M9 12h6m-6 4h6M7 3h6l4 4v14H7zM13 3v5h5"/></svg>
                    </span>
                    <div>
                        <p id="proposal-detail-modal-event-title" class="proposal-modal-kicker">Event proposal</p>
                        <h2 id="proposal-detail-modal-title" class="proposal-modal-title">Proposal Details</h2>
                        <p class="proposal-modal-subtitle">Review the submitted PPA metadata before taking OSA action.</p>
                    </div>
                </div>
                <button type="button" class="btn-icon btn-ghost proposal-detail-close" aria-label="Close proposal details">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>

            <div class="proposal-modal-body">
                <section class="proposal-modal-section">
                    <h3 class="proposal-detail-heading">Identity</h3>
                    <div class="proposal-detail-grid">
                        <div class="proposal-detail-item">
                            <span class="proposal-detail-label">Status</span>
                            <span id="proposal-detail-status" class="proposal-detail-value"></span>
                        </div>
                        <div class="proposal-detail-item">
                            <span class="proposal-detail-label">Organizer</span>
                            <span id="proposal-detail-organizer" class="proposal-detail-value"></span>
                        </div>
                        <div class="proposal-detail-item">
                            <span class="proposal-detail-label">PPA category</span>
                            <span id="proposal-detail-category" class="proposal-detail-value"></span>
                        </div>
                        <div class="proposal-detail-item">
                            <span class="proposal-detail-label">Parent permit</span>
                            <span id="proposal-detail-parent" class="proposal-detail-value"></span>
                        </div>
                    </div>
                </section>

                <section class="proposal-modal-section">
                    <h3 class="proposal-detail-heading">Schedule and venue</h3>
                    <div class="proposal-detail-grid">
                        <div class="proposal-detail-item">
                            <span class="proposal-detail-label">Schedule</span>
                            <span id="proposal-detail-schedule" class="proposal-detail-value"></span>
                        </div>
                        <div class="proposal-detail-item">
                            <span class="proposal-detail-label">Start time</span>
                            <span id="proposal-detail-start-time" class="proposal-detail-value"></span>
                        </div>
                        <div class="proposal-detail-item">
                            <span class="proposal-detail-label">End time</span>
                            <span id="proposal-detail-end-time" class="proposal-detail-value"></span>
                        </div>
                        <div class="proposal-detail-item">
                            <span class="proposal-detail-label">Location type</span>
                            <span id="proposal-detail-location-type" class="proposal-detail-value"></span>
                        </div>
                        <div class="proposal-detail-item proposal-detail-wide">
                            <span class="proposal-detail-label">Location details</span>
                            <span id="proposal-detail-location-details" class="proposal-detail-value"></span>
                        </div>
                        <div class="proposal-detail-item proposal-detail-wide">
                            <span class="proposal-detail-label">Event days</span>
                            <span id="proposal-detail-days" class="proposal-detail-value"></span>
                        </div>
                    </div>
                </section>

                <section class="proposal-modal-section">
                    <h3 class="proposal-detail-heading">Offices, participants, and budget</h3>
                    <div class="proposal-detail-grid">
                        <div class="proposal-detail-item">
                            <span class="proposal-detail-label">Implementing office</span>
                            <span id="proposal-detail-implementing-office" class="proposal-detail-value"></span>
                        </div>
                        <div class="proposal-detail-item">
                            <span class="proposal-detail-label">Collaborating office</span>
                            <span id="proposal-detail-collaborating-office" class="proposal-detail-value"></span>
                        </div>
                        <div class="proposal-detail-item">
                            <span class="proposal-detail-label">Source of fund</span>
                            <span id="proposal-detail-source-of-fund" class="proposal-detail-value"></span>
                        </div>
                        <div class="proposal-detail-item">
                            <span class="proposal-detail-label">Budget / cost</span>
                            <span id="proposal-detail-budget-cost" class="proposal-detail-value"></span>
                        </div>
                        <div class="proposal-detail-item proposal-detail-wide">
                            <span class="proposal-detail-label">Target beneficiaries / participants</span>
                            <span id="proposal-detail-target-participants" class="proposal-detail-value"></span>
                        </div>
                    </div>
                </section>

                <section class="proposal-modal-section">
                    <h3 class="proposal-detail-heading">Official PPA packet</h3>
                    <div class="proposal-detail-grid">
                        <div class="proposal-detail-item">
                            <span class="proposal-detail-label">Resolution number</span>
                            <span id="proposal-detail-resolution-number" class="proposal-detail-value"></span>
                        </div>
                        <div class="proposal-detail-item">
                            <span class="proposal-detail-label">Proposal softcopy</span>
                            <span class="proposal-detail-value">
                                <a id="proposal-detail-softcopy-link" href="#" class="text-(--color-accent) hover:underline">Download softcopy</a>
                                <span id="proposal-detail-softcopy-missing">Not attached</span>
                            </span>
                        </div>
                        <div class="proposal-detail-item">
                            <span class="proposal-detail-label">Hardcopy</span>
                            <span id="proposal-detail-hardcopy" class="proposal-detail-value"></span>
                        </div>
                        <div class="proposal-detail-item">
                            <span class="proposal-detail-label">Signatures</span>
                            <span class="proposal-detail-value">
                                Head <span id="proposal-detail-head-signed"></span>,
                                Adviser <span id="proposal-detail-adviser-signed"></span>
                            </span>
                        </div>
                    </div>
                </section>

                <section class="proposal-modal-section">
                    <h3 class="proposal-detail-heading">Evaluation form</h3>
                    <div class="proposal-detail-grid">
                        <div class="proposal-detail-item proposal-detail-wide">
                            <span class="proposal-detail-label">Questions</span>
                            <span id="proposal-detail-evaluation-questions" class="proposal-detail-value"></span>
                        </div>
                    </div>
                </section>
            </div>

            <div class="proposal-modal-footer">
                <p class="proposal-required-note">OSA review metadata</p>
                <div class="proposal-footer-actions">
                    <button type="button" class="btn btn-secondary proposal-detail-close">Close</button>
                </div>
            </div>
        </div>
    </dialog>
    @endif

    {{-- Export Options Modal --}}
    <dialog id="export-modal"
            class="proposal-modal modal-sm"
            aria-labelledby="export-modal-title">
        <div class="proposal-modal-form">
            <div class="proposal-modal-header">
                <div class="proposal-modal-title-row">
                    <span class="proposal-modal-icon" aria-hidden="true">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M12 3v12m0 0l4-4m-4 4l-4-4M5 21h14"/></svg>
                    </span>
                    <div>
                        <p id="export-modal-event-title" class="proposal-modal-kicker">Event proposal</p>
                        <h2 id="export-modal-title" class="proposal-modal-title">Export Event</h2>
                    </div>
                </div>
                <button type="button" class="btn-icon btn-ghost export-modal-close" aria-label="Close export modal">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>

            <div class="proposal-modal-body">
                <div class="export-option-grid">
                    <section class="export-option-section" aria-labelledby="attendance-export-title">
                        <h3 id="attendance-export-title" class="export-option-heading">Attendance</h3>
                        <div class="export-option-actions">
                            <a id="export-modal-attendance-pdf" class="export-option-card export-option-pdf" href="#">
                                <span class="export-option-icon" aria-hidden="true">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M7 3h7l5 5v13H7zM14 3v5h5"/></svg>
                                </span>
                                <span>
                                    <span class="export-option-type">PDF</span>
                                    <span class="export-option-label">Attendance report</span>
                                </span>
                            </a>
                            <a id="export-modal-attendance-excel" class="export-option-card export-option-xlsx" href="#">
                                <span class="export-option-icon" aria-hidden="true">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 5h16v14H4zM4 10h16M9 5v14"/></svg>
                                </span>
                                <span>
                                    <span class="export-option-type">Excel</span>
                                    <span class="export-option-label">Attendance report</span>
                                </span>
                            </a>
                        </div>
                    </section>

                    <section class="export-option-section" aria-labelledby="evaluation-export-title">
                        <h3 id="evaluation-export-title" class="export-option-heading">Evaluation</h3>
                        <div class="export-option-actions">
                            <a id="export-modal-evaluations-pdf" class="export-option-card export-option-pdf" href="#">
                                <span class="export-option-icon" aria-hidden="true">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M7 3h7l5 5v13H7zM14 3v5h5"/></svg>
                                </span>
                                <span>
                                    <span class="export-option-type">PDF</span>
                                    <span class="export-option-label">Evaluation report</span>
                                </span>
                            </a>
                            <a id="export-modal-evaluations-excel" class="export-option-card export-option-xlsx" href="#">
                                <span class="export-option-icon" aria-hidden="true">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 5h16v14H4zM4 10h16M9 5v14"/></svg>
                                </span>
                                <span>
                                    <span class="export-option-type">Excel</span>
                                    <span class="export-option-label">Evaluation report</span>
                                </span>
                            </a>
                        </div>
                    </section>
                </div>
            </div>

            <div class="proposal-modal-footer">
                <p class="proposal-required-note">PDF and Excel outputs</p>
                <div class="proposal-footer-actions">
                    <button type="button" class="btn btn-secondary export-modal-close">Close</button>
                </div>
            </div>
        </div>
    </dialog>

    {{-- Real-Time Analytics Modal --}}
    <dialog id="analytics-modal"
            class="proposal-modal modal-md"
            aria-labelledby="analytics-modal-title"
            aria-describedby="analytics-modal-description"
            x-data="analyticsModal"
            @open-analytics.window="openModal($event.detail)"
            onclick="if (event.target === this) closeModal()">
        <div class="proposal-modal-form">
            <div class="proposal-modal-header">
                <div class="proposal-modal-title-row">
                    <span class="proposal-modal-icon" aria-hidden="true">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                    </span>
                    <div>
                        <p class="proposal-modal-kicker" x-text="eventTitle || 'Event Analytics'"></p>
                        <h2 id="analytics-modal-title" class="proposal-modal-title">Real-Time Event Analytics</h2>
                        <p id="analytics-modal-description" class="proposal-modal-subtitle">Aggregated evaluation rates, sentiment scores, and participant demographics.</p>
                    </div>
                </div>
                <button type="button" @click="closeModal()" class="btn-icon btn-ghost" aria-label="Close analytics modal">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>

            <div class="proposal-modal-body">
                {{-- Loading State --}}
                <div x-show="loading" class="py-12 flex flex-col items-center justify-center gap-3">
                    <div class="w-8 h-8 rounded-full border-4 border-(--color-accent-light) border-t-(--color-accent) animate-spin"></div>
                    <p class="text-sm text-(--color-text-secondary)">Retrieving real-time analytics...</p>
                </div>

                {{-- Content State --}}
                <div x-show="!loading" class="space-y-4">
                    {{-- Grid 1: Attendance & Evaluations Summary --}}
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        {{-- Attendance Card --}}
                        <div class="proposal-modal-section">
                            <h3 class="font-semibold text-sm text-(--color-text-primary) mb-3 flex items-center gap-2">
                                <svg class="w-4 h-4 text-(--color-accent)" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                                Attendance Rate
                            </h3>
                            <div class="flex items-baseline gap-2 mb-1">
                                <span class="text-2xl font-bold text-(--color-text-primary)" x-text="(attendance.attendance_rate || 0) + '%'"></span>
                                <span class="text-xs text-(--color-text-tertiary)" x-text="'(' + (attendance.attended || 0) + ' / ' + (attendance.total_demographic || 0) + ' participants)'"></span>
                            </div>
                            <div class="w-full bg-(--color-surface-raised) h-2 rounded-full overflow-hidden">
                                <div class="bg-(--color-accent) h-full transition-all duration-500" :style="'width: ' + (attendance.attendance_rate || 0) + '%'"></div>
                            </div>
                        </div>

                        {{-- Evaluation Rating Card --}}
                        <div class="proposal-modal-section">
                            <h3 class="font-semibold text-sm text-(--color-text-primary) mb-3 flex items-center gap-2">
                                <svg class="w-4 h-4 text-amber-500" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
                                Evaluation Rating
                            </h3>
                            <div class="flex items-baseline gap-2">
                                <span class="text-2xl font-bold text-(--color-text-primary)" x-text="(evaluations.average_rating || '0.00')"></span>
                                <span class="text-xs text-(--color-text-tertiary)">/ 5.00 average</span>
                            </div>
                            <p class="text-xs text-(--color-text-tertiary) mt-1" x-text="'Total evaluations: ' + (evaluations.total_submitted || 0)"></p>
                        </div>
                    </div>

                    {{-- Scope Rollups --}}
                    <div class="proposal-modal-section">
                        <h3 class="font-semibold text-sm text-(--color-text-primary) mb-3 flex items-center gap-2">
                            <svg class="w-4 h-4 text-(--color-accent)" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M8.25 6.75h7.5M8.25 12h7.5m-7.5 5.25h4.5M4.5 5.25A2.25 2.25 0 016.75 3h10.5a2.25 2.25 0 012.25 2.25v13.5A2.25 2.25 0 0117.25 21H6.75a2.25 2.25 0 01-2.25-2.25V5.25z"/></svg>
                            Participant Scope Roll-up
                        </h3>
                        <div class="space-y-2">
                            <template x-for="scope in scopeRollups" :key="scope.id">
                                <div class="rounded-lg border border-(--color-border) bg-(--color-surface-raised) px-3 py-2">
                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                        <div class="min-w-0">
                                            <p class="text-sm font-semibold text-(--color-text-primary) truncate" x-text="scope.name"></p>
                                            <p class="text-xs text-(--color-text-tertiary)" x-text="scope.participant_type + ' · ' + scope.capture_mode + ' · ' + scope.ppa_status"></p>
                                        </div>
                                        <div class="text-right text-xs">
                                            <p class="font-semibold text-(--color-text-primary)" x-text="scope.attendance_rate + '%'"></p>
                                            <p class="text-(--color-text-tertiary)" x-text="scope.attended + ' / ' + scope.registered"></p>
                                        </div>
                                    </div>
                                </div>
                            </template>
                            <div x-show="scopeRollups.length === 0" class="text-xs text-(--color-text-tertiary) italic">
                                No approved participant scopes are available for roll-up yet.
                            </div>
                        </div>
                    </div>

                    {{-- Grid 2: Sentiments --}}
                    <div class="proposal-modal-section">
                        <h3 class="font-semibold text-sm text-(--color-text-primary) mb-3 flex items-center gap-2">
                            <svg class="w-4 h-4 text-(--color-accent)" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"/></svg>
                            Comment Sentiments ({{ config('services.ai.provider') === 'ollama' ? 'Ollama' : 'Gemini' }} NLP Classifier)
                        </h3>
                        <div class="space-y-2">
                            {{-- Positive Sentiment --}}
                            <div>
                                <div class="flex justify-between text-xs text-(--color-text-secondary) mb-1">
                                    <span class="font-medium text-emerald-600">Positive Comments</span>
                                    <span x-text="evaluations.sentiments.positive + ' comment(s)'"></span>
                                </div>
                                <div class="w-full bg-(--color-surface-raised) h-2 rounded-full overflow-hidden">
                                    <div class="bg-emerald-500 h-full transition-all duration-500" :style="'width: ' + getPercentage(evaluations.sentiments.positive, evaluations.total_submitted) + '%'"></div>
                                </div>
                            </div>
                            {{-- Neutral Sentiment --}}
                            <div>
                                <div class="flex justify-between text-xs text-(--color-text-secondary) mb-1">
                                    <span class="font-medium text-slate-500">Neutral Comments</span>
                                    <span x-text="evaluations.sentiments.neutral + ' comment(s)'"></span>
                                </div>
                                <div class="w-full bg-(--color-surface-raised) h-2 rounded-full overflow-hidden">
                                    <div class="bg-slate-400 h-full transition-all duration-500" :style="'width: ' + getPercentage(evaluations.sentiments.neutral, evaluations.total_submitted) + '%'"></div>
                                </div>
                            </div>
                            {{-- Negative Sentiment --}}
                            <div>
                                <div class="flex justify-between text-xs text-(--color-text-secondary) mb-1">
                                    <span class="font-medium text-rose-600">Negative Comments</span>
                                    <span x-text="evaluations.sentiments.negative + ' comment(s)'"></span>
                                </div>
                                <div class="w-full bg-(--color-surface-raised) h-2 rounded-full overflow-hidden">
                                    <div class="bg-rose-500 h-full transition-all duration-500" :style="'width: ' + getPercentage(evaluations.sentiments.negative, evaluations.total_submitted) + '%'"></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- Section 3: Demographic Aggregations --}}
                    <div class="proposal-modal-section">
                        <h3 class="font-semibold text-sm text-(--color-text-primary) mb-3 flex items-center gap-2">
                            <svg class="w-4 h-4 text-(--color-accent)" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
                            Participant Demographic Breakdown
                        </h3>
                        <div class="flex flex-wrap gap-2">
                            <template x-for="demo in demographics" :key="demo.program_code">
                                <div class="px-3 py-1.5 rounded-lg border border-(--color-border) bg-(--color-surface-raised) flex items-center gap-2 text-xs">
                                    <span class="font-bold text-(--color-accent)" x-text="demo.program_code || 'N/A'"></span>
                                    <span class="w-px h-3 bg-white/20"></span>
                                    <span class="text-(--color-text-secondary)" x-text="demo.count + ' student(s)'"></span>
                                </div>
                            </template>
                            <div x-show="demographics.length === 0" class="text-xs text-(--color-text-tertiary) italic">
                                No demographic details available yet.
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="proposal-modal-footer">
                <p class="proposal-required-note">Real-time attendance & feedback metrics</p>
                <div class="proposal-footer-actions">
                    <button type="button" @click="closeModal()" class="btn btn-secondary">Close</button>
                </div>
            </div>
        </div>
    </dialog>

    <script>
        window.initEventsDashboard = () => {
            const modal = document.getElementById('export-modal');
            if (!modal) return;

            const title = document.getElementById('export-modal-event-title');
            const firstLink = document.getElementById('export-modal-attendance-pdf');
            const linkTargets = {
                attendancePdf: firstLink,
                attendanceExcel: document.getElementById('export-modal-attendance-excel'),
                evaluationsPdf: document.getElementById('export-modal-evaluations-pdf'),
                evaluationsExcel: document.getElementById('export-modal-evaluations-excel'),
            };

            const closeModal = () => {
                if (modal.open) {
                    modal.close();
                }
            };

            document.querySelectorAll('[data-export-trigger]').forEach((button) => {
                if (button.dataset.exportBound === 'true') {
                    return;
                }

                button.dataset.exportBound = 'true';
                button.addEventListener('click', () => {
                    const data = button.dataset;
                    title.textContent = data.exportTitle || 'Event proposal';
                    linkTargets.attendancePdf.href = data.attendancePdf || '#';
                    linkTargets.attendanceExcel.href = data.attendanceExcel || '#';
                    linkTargets.evaluationsPdf.href = data.evaluationsPdf || '#';
                    linkTargets.evaluationsExcel.href = data.evaluationsExcel || '#';

                    if (modal.showModal && !modal.open) {
                        modal.showModal();
                    }
                    firstLink?.focus();
                });
            });

            if (modal.dataset.exportModalBound !== 'true') {
                modal.dataset.exportModalBound = 'true';
                modal.addEventListener('click', (event) => {
                    if (event.target === modal) {
                        closeModal();
                    }
                });
            }

            document.querySelectorAll('.export-modal-close').forEach((button) => {
                if (button.dataset.exportCloseBound === 'true') {
                    return;
                }

                button.dataset.exportCloseBound = 'true';
                button.addEventListener('click', closeModal);
            });

            const detailModal = document.getElementById('proposal-detail-modal');
            if (detailModal) {
                const detailTitle = document.getElementById('proposal-detail-modal-event-title');
                const softcopyLink = document.getElementById('proposal-detail-softcopy-link');
                const softcopyMissing = document.getElementById('proposal-detail-softcopy-missing');
                const detailFields = {
                    status: document.getElementById('proposal-detail-status'),
                    organizer: document.getElementById('proposal-detail-organizer'),
                    category: document.getElementById('proposal-detail-category'),
                    parent: document.getElementById('proposal-detail-parent'),
                    schedule: document.getElementById('proposal-detail-schedule'),
                    startTime: document.getElementById('proposal-detail-start-time'),
                    endTime: document.getElementById('proposal-detail-end-time'),
                    days: document.getElementById('proposal-detail-days'),
                    locationType: document.getElementById('proposal-detail-location-type'),
                    locationDetails: document.getElementById('proposal-detail-location-details'),
                    implementingOffice: document.getElementById('proposal-detail-implementing-office'),
                    collaboratingOffice: document.getElementById('proposal-detail-collaborating-office'),
                    targetParticipants: document.getElementById('proposal-detail-target-participants'),
                    sourceOfFund: document.getElementById('proposal-detail-source-of-fund'),
                    budgetCost: document.getElementById('proposal-detail-budget-cost'),
                    resolutionNumber: document.getElementById('proposal-detail-resolution-number'),
                    hardcopy: document.getElementById('proposal-detail-hardcopy'),
                    headSigned: document.getElementById('proposal-detail-head-signed'),
                    adviserSigned: document.getElementById('proposal-detail-adviser-signed'),
                    evaluationQuestions: document.getElementById('proposal-detail-evaluation-questions'),
                };

                const closeDetailModal = () => {
                    if (detailModal.open) {
                        detailModal.close();
                    }
                };

                document.querySelectorAll('[data-detail-trigger]').forEach((button) => {
                    if (button.dataset.detailBound === 'true') {
                        return;
                    }

                    button.dataset.detailBound = 'true';
                    button.addEventListener('click', () => {
                        const data = button.dataset;
                        detailTitle.textContent = data.detailTitle || 'Event proposal';

                        Object.entries(detailFields).forEach(([key, field]) => {
                            if (field) {
                                field.textContent = data[`detail${key.charAt(0).toUpperCase()}${key.slice(1)}`] || 'N/A';
                            }
                        });

                        if (data.detailSoftcopyUrl) {
                            softcopyLink.href = data.detailSoftcopyUrl;
                            softcopyLink.textContent = data.detailSoftcopyName || 'Download softcopy';
                            softcopyLink.hidden = false;
                            softcopyMissing.hidden = true;
                        } else {
                            softcopyLink.hidden = true;
                            softcopyMissing.hidden = false;
                        }

                        if (detailModal.showModal && !detailModal.open) {
                            detailModal.showModal();
                        }
                        detailModal.querySelector('.proposal-detail-close')?.focus();
                    });
                });

                if (detailModal.dataset.detailModalBound !== 'true') {
                    detailModal.dataset.detailModalBound = 'true';
                    detailModal.addEventListener('click', (event) => {
                        if (event.target === detailModal) {
                            closeDetailModal();
                        }
                    });
                }

                document.querySelectorAll('.proposal-detail-close').forEach((button) => {
                    if (button.dataset.detailCloseBound === 'true') {
                        return;
                    }

                    button.dataset.detailCloseBound = 'true';
                    button.addEventListener('click', closeDetailModal);
                });
            }

            document.querySelectorAll('[data-cutoff-choice]').forEach((button) => {
                if (button.dataset.cutoffBound === 'true') {
                    return;
                }

                button.dataset.cutoffBound = 'true';
                button.addEventListener('click', () => {
                    const target = document.getElementById(button.dataset.cutoffTarget || '');

                    if (!target || target.disabled) {
                        return;
                    }

                    target.value = button.dataset.cutoffValue ?? '';
                    target.focus();
                    target.dispatchEvent(new Event('input', { bubbles: true }));
                    target.dispatchEvent(new Event('change', { bubbles: true }));
                });
            });

            const actionMenus = Array.from(document.querySelectorAll('[data-event-action-menu]'));
            const positionActionMenu = (menu) => {
                const summary = menu.querySelector('summary');
                const panel = menu.querySelector('.event-action-menu-panel');

                if (!summary || !panel) {
                    return;
                }

                const rect = summary.getBoundingClientRect();
                const viewportPadding = 16;
                const top = Math.min(
                    rect.bottom + 8,
                    window.innerHeight - Math.min(panel.scrollHeight || 320, 384) - viewportPadding
                );
                const right = Math.max(window.innerWidth - rect.right, viewportPadding);

                panel.style.setProperty('--event-action-menu-top', `${Math.max(viewportPadding, top)}px`);
                panel.style.setProperty('--event-action-menu-right', `${right}px`);
            };
            const closeActionMenus = (except = null) => {
                actionMenus.forEach((menu) => {
                    if (menu !== except) {
                        menu.removeAttribute('open');
                        menu.querySelector('summary')?.setAttribute('aria-expanded', 'false');
                    }
                });
            };

            actionMenus.forEach((menu) => {
                if (menu.dataset.actionMenuBound === 'true') {
                    return;
                }

                menu.dataset.actionMenuBound = 'true';
                menu.addEventListener('toggle', () => {
                    const summary = menu.querySelector('summary');
                    summary?.setAttribute('aria-expanded', menu.open ? 'true' : 'false');

                    if (menu.open) {
                        positionActionMenu(menu);
                        closeActionMenus(menu);
                    }
                });

                const panel = menu.querySelector('.event-action-menu-panel');
                if (panel && panel.dataset.actionPanelBound !== 'true') {
                    panel.dataset.actionPanelBound = 'true';
                    panel.addEventListener('click', (event) => {
                        if (event.target.closest('button, a')) {
                            window.setTimeout(() => closeActionMenus(), 0);
                        }
                    });
                }
            });

            const repositionOpenActionMenu = () => {
                actionMenus.forEach((menu) => {
                    if (menu.open) {
                        positionActionMenu(menu);
                    }
                });
            };

            window.__eventsDashboardCloseActionMenus = closeActionMenus;
            window.__eventsDashboardRepositionActionMenu = repositionOpenActionMenu;

            if (window.__eventsDashboardGlobalBound !== true) {
                window.__eventsDashboardGlobalBound = true;
                document.addEventListener('click', (event) => {
                    if (!event.target.closest('[data-event-action-menu]')) {
                        window.__eventsDashboardCloseActionMenus?.();
                    }
                });

                document.addEventListener('keydown', (event) => {
                    if (event.key === 'Escape') {
                        window.__eventsDashboardCloseActionMenus?.();
                    }
                });

                window.addEventListener('resize', () => window.__eventsDashboardRepositionActionMenu?.());
                window.addEventListener('scroll', () => window.__eventsDashboardRepositionActionMenu?.(), true);
            }
        };

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', window.initEventsDashboard);
        } else {
            window.initEventsDashboard();
        }

        if (window.__eventsDashboardNavigateBound !== true) {
            window.__eventsDashboardNavigateBound = true;
            document.addEventListener('livewire:navigated', () => window.initEventsDashboard?.());
        }

        const bindEventsWorkspaceMorphHook = () => {
            if (window.__eventsWorkspaceMorphBound === true || !window.Livewire?.hook) {
                return;
            }

            window.__eventsWorkspaceMorphBound = true;
            window.Livewire.hook('morphed', ({ component }) => {
                if (component.name === 'dashboard.events-workspace') {
                    requestAnimationFrame(() => window.initEventsDashboard?.());
                }
            });
        };

        document.addEventListener('livewire:initialized', bindEventsWorkspaceMorphHook);
        bindEventsWorkspaceMorphHook();

        document.addEventListener('alpine:init', () => {
            Alpine.data('analyticsModal', () => ({
                loading: false,
                eventId: null,
                eventTitle: '',
                attendance: { total_demographic: 0, attended: 0, attendance_rate: 0 },
                evaluations: { total_submitted: 0, average_rating: 0, sentiments: { positive: 0, neutral: 0, negative: 0 } },
                demographics: [],
                scopeRollups: [],

                async openModal(detail) {
                    this.eventId = detail.id;
                    this.eventTitle = detail.title;
                    this.loading = true;
                    
                    const modal = document.getElementById('analytics-modal');
                    if (modal?.showModal && !modal.open) {
                        modal.showModal();
                    }

                    try {
                        const response = await fetch(`/dashboard/events/${this.eventId}/analytics/data`);
                        if (!response.ok) throw new Error('Failed to retrieve analytics');
                        const data = await response.json();
                        
                        this.attendance = data.attendance;
                        this.evaluations = data.evaluations;
                        this.demographics = data.demographics || [];
                        this.scopeRollups = data.attendance?.participant_scopes || [];
                    } catch (e) {
                        console.error(e);
                        alert('Could not retrieve real-time analytics data. Please try again.');
                        this.closeModal();
                    } finally {
                        this.loading = false;
                    }
                },

                closeModal() {
                    const modal = document.getElementById('analytics-modal');
                    if (modal?.open) {
                        modal.close();
                    }
                    this.eventId = null;
                    this.eventTitle = '';
                },

                getPercentage(value, total) {
                    if (!total || total <= 0) return 0;
                    return Math.round((value / total) * 100);
                }
            }));
        });
    </script>

    @if($errors->any())
        <script>
            document.addEventListener('DOMContentLoaded', () => {
                const modal = document.getElementById('create-event-modal');
                if (modal?.showModal && !modal.open) {
                    modal.showModal();
                    setTimeout(() => {
                        const summary = modal.querySelector('#proposal-create-error-summary, #proposal-create-error-notice');
                        const body = modal.querySelector('.proposal-modal-body');

                        body?.scrollTo({ top: 0, behavior: 'auto' });
                        (summary ?? modal.querySelector('.form-input-error'))?.focus();
                    }, 50);
                }
            });
        </script>
    @endif
</x-layouts.app>
