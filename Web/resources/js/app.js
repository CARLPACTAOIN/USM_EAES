import './bootstrap';
import { Livewire, Alpine } from '../../vendor/livewire/livewire/dist/livewire.esm';

/* ─── Toast Notification System ──────────────────────────── */
Alpine.data('toastManager', () => ({
    toasts: [],
    addToast(message, type = 'info', duration = 4000) {
        const id = Date.now();
        this.toasts.push({ id, message, type });
        setTimeout(() => this.removeToast(id), duration);
    },
    removeToast(id) {
        this.toasts = this.toasts.filter(t => t.id !== id);
    }
}));

/* ─── Sidebar Toggle ─────────────────────────────────────── */
Alpine.data('sidebar', () => ({
    open: false,
    toggle() { this.open = !this.open; },
    close() { this.open = false; }
}));

/* ─── Dynamic Event Day Rows + Evaluation Form ───────────── */
Alpine.data('eventDaysForm', (initialDays = [], initialQuestions = []) => ({
    days: Array.isArray(initialDays) && initialDays.length
        ? initialDays.map((day, index) => ({
            day_number: index + 1,
            date: day.date || '',
            start_time: day.start_time || '',
            end_time: day.end_time || ''
        }))
        : [{ day_number: 1, date: '', start_time: '', end_time: '' }],
    evaluationQuestions: Array.isArray(initialQuestions) && initialQuestions.length
        ? initialQuestions.map((question, index) => ({
            uid: `existing-${index}`,
            id: question.id || '',
            label: question.label || '',
            description: question.description || ''
        }))
        : [
            {
                uid: 'default-1',
                id: 'attainment_of_objectives',
                label: 'Attainment of objectives',
                description: 'Were the stated objectives of the activity met?'
            },
            {
                uid: 'default-2',
                id: 'speaker_mastery',
                label: 'Speaker or facilitator mastery',
                description: 'How well did the speaker or facilitator deliver the content?'
            },
            {
                uid: 'default-3',
                id: 'venue_comfort',
                label: 'Venue and comfort',
                description: 'Was the venue appropriate, comfortable, and well prepared?'
            }
        ],
    previousStartDate: '',
    init() {
        const startInput = document.getElementById('start_date');
        const endInput = document.getElementById('end_date');

        this.applyStartDate(startInput?.value || '', endInput);
        this.previousStartDate = startInput?.value || '';

        startInput?.addEventListener('change', () => {
            this.applyStartDate(startInput.value, endInput);
        });
    },
    applyStartDate(value, endInput = document.getElementById('end_date')) {
        if (!value) {
            this.previousStartDate = '';
            return;
        }

        if (endInput && (!endInput.value || endInput.value === this.previousStartDate)) {
            endInput.value = value;
        }

        if (this.days[0] && (!this.days[0].date || this.days[0].date === this.previousStartDate)) {
            this.days[0].date = value;
        }

        this.previousStartDate = value;
    },
    addDay() {
        this.days.push({
            day_number: this.days.length + 1,
            date: '',
            start_time: '',
            end_time: ''
        });
    },
    removeDay(index) {
        if (this.days.length > 1) {
            this.days.splice(index, 1);
            this.days.forEach((d, i) => d.day_number = i + 1);
        }
    },
    addQuestion() {
        if (this.evaluationQuestions.length >= 10) {
            return;
        }

        this.evaluationQuestions.push({
            uid: `new-${Date.now()}-${this.evaluationQuestions.length}`,
            id: '',
            label: '',
            description: ''
        });
    },
    removeQuestion(index) {
        if (this.evaluationQuestions.length > 1) {
            this.evaluationQuestions.splice(index, 1);
        }
    }
}));

/* ─── Livewire Event Schedule Editor ─────────────────────── */
Alpine.data('eventScheduleEditor', (initialDays, initialStartDate, initialEndDate, initialSameTime, initialErrors = {}) => ({
    days: initialDays,
    startDate: initialStartDate,
    endDate: initialEndDate,
    sameTimeForAllDays: initialSameTime,
    errors: initialErrors || {},
    init() {
        if (!Array.isArray(this.days) || this.days.length === 0) {
            this.days = [this.blankDay()];
        }

        this.days.forEach(day => this.ensureDaySessions(day));
    },
    blankDay() {
        return { date: '', start_time: '', end_time: '', sessions: [] };
    },
    blankSession(day = {}, index = 0) {
        return {
            name: `Session ${index + 1}`,
            start_time: day.start_time || '',
            end_time: day.end_time || '',
            required_for_attendance: true,
        };
    },
    ensureDaySessions(day) {
        if (!Array.isArray(day.sessions) || day.sessions.length === 0) {
            day.sessions = [this.blankSession(day, 0)];
        }

        return day.sessions;
    },
    addSession(dayIndex) {
        const day = this.days[dayIndex];

        if (!day) {
            return;
        }

        const sessions = this.ensureDaySessions(day);
        sessions.push(this.blankSession(day, sessions.length));
        this.days = [...this.days];
        this.clearScheduleErrors();
    },
    removeSession(dayIndex, sessionIndex) {
        const day = this.days[dayIndex];

        if (!day) {
            return;
        }

        const sessions = this.ensureDaySessions(day);

        if (sessions.length <= 1) {
            return;
        }

        sessions.splice(sessionIndex, 1);
        this.days = [...this.days];
        this.clearScheduleErrors();
    },
    splitDay(dayIndex) {
        const day = this.days[dayIndex];

        if (!day?.start_time || !day?.end_time) {
            return;
        }

        day.sessions = [
            { name: 'Session 1', start_time: day.start_time, end_time: '12:00', required_for_attendance: true },
            { name: 'Session 2', start_time: '13:00', end_time: day.end_time, required_for_attendance: true },
        ];
        this.days = [...this.days];
        this.clearScheduleErrors();
    },
    parseDate(value) {
        const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value || '');

        if (!match) {
            return null;
        }

        const date = new Date(Date.UTC(Number(match[1]), Number(match[2]) - 1, Number(match[3])));

        return this.formatDate(date) === value ? date : null;
    },
    formatDate(date) {
        const year = date.getUTCFullYear();
        const month = String(date.getUTCMonth() + 1).padStart(2, '0');
        const day = String(date.getUTCDate()).padStart(2, '0');

        return `${year}-${month}-${day}`;
    },
    handleStartDateChange() {
        const start = this.parseDate(this.startDate);
        const end = this.parseDate(this.endDate);

        if (!start) {
            return;
        }

        if (!end || end < start) {
            this.endDate = this.startDate;
        }

        this.syncDaysToDateRange();
    },
    handleEndDateChange() {
        const end = this.parseDate(this.endDate);

        if (!end) {
            return;
        }

        if (!this.parseDate(this.startDate)) {
            this.startDate = this.endDate;
        }

        this.syncDaysToDateRange();
    },
    addDay() {
        const end = this.parseDate(this.endDate);

        if (end) {
            end.setUTCDate(end.getUTCDate() + 1);
            this.endDate = this.formatDate(end);
            this.syncDaysToDateRange();
        } else {
            this.days = [...this.days, this.blankDay()];
        }

        this.clearScheduleErrors();
    },
    removeDay(index) {
        if (this.days.length <= 1) {
            return;
        }

        this.days.splice(index, 1);
        this.days = [...this.days];
        this.clearScheduleErrors();
    },
    syncDaysToDateRange() {
        const start = this.parseDate(this.startDate);
        const end = this.parseDate(this.endDate);

        if (!start || !end || end < start) {
            return;
        }

        const existingDays = Array.isArray(this.days) ? this.days : [];
        const daysByDate = new Map(
            existingDays
                .filter(day => day?.date)
                .map(day => [day.date, day])
        );
        const nextDays = [];
        const cursor = new Date(start.getTime());

        while (cursor <= end) {
            const date = this.formatDate(cursor);
            const previous = daysByDate.get(date) || existingDays[nextDays.length] || {};

            nextDays.push({
                date,
                start_time: previous.start_time || '',
                end_time: previous.end_time || '',
                sessions: Array.isArray(previous.sessions) ? previous.sessions : [],
            });
            cursor.setUTCDate(cursor.getUTCDate() + 1);
        }

        this.days = nextDays;

        if (this.sameTimeForAllDays) {
            this.applyFirstDayTimesToAllDays();
        }

        this.clearScheduleErrors();
    },
    applyFirstDayTimesToAllDays() {
        const firstDay = this.days[0];

        if (!firstDay) {
            return;
        }

        this.days.forEach((day) => {
            if (firstDay.start_time) {
                day.start_time = firstDay.start_time;
            }

            if (firstDay.end_time) {
                day.end_time = firstDay.end_time;
            }

            if (Array.isArray(day.sessions) && day.sessions.length === 1) {
                day.sessions[0].start_time = day.start_time;
                day.sessions[0].end_time = day.end_time;
            }
        });
    },
    syncDayTime(index, field) {
        if (!this.sameTimeForAllDays || !this.days[index]) {
            return;
        }

        const value = this.days[index][field] || '';
        this.days.forEach(day => {
            day[field] = value;

            if (Array.isArray(day.sessions) && day.sessions.length === 1) {
                day.sessions[0][field] = value;
            }
        });
    },
    dayError(index, field) {
        const messages = this.errors[`eventDays.${index}.${field}`];

        return Array.isArray(messages) ? messages[0] : (messages || '');
    },
    sessionError(dayIndex, sessionIndex, field) {
        const messages = this.errors[`eventDays.${dayIndex}.sessions.${sessionIndex}.${field}`];

        return Array.isArray(messages) ? messages[0] : (messages || '');
    },
    clearScheduleErrors() {
        this.errors = Object.fromEntries(
            Object.entries(this.errors).filter(([key]) => !key.startsWith('eventDays.'))
        );
    }
}));

/* ─── Admin Access Assignment Form ───────────────────────── */
Alpine.data('adminAssignmentForm', (config) => ({
    searchUrl: config.searchUrl,
    organizations: config.organizations || [],
    colleges: config.colleges || [],
    role: config.oldRole || '',
    collegeId: config.oldCollegeId || '',
    organizationId: config.oldOrganizationId || '',
    email: config.oldEmail || '',
    studentId: config.oldStudentId || '',
    name: config.oldName || '',
    searchQuery: config.oldPersonQuery || '',
    results: [],
    showResults: false,
    userLocked: !!(config.oldEmail || config.oldStudentId),
    accountMode: config.oldAccountMode || 'search',
    roleTypes: {
        'USG Admin': 'usg',
        'LSG Admin': 'lsg',
        'Society Admin': ['society', 'non_academic_org'],
        'Academic Unit Admin': 'academic_unit',
        'Office Event Admin': 'administrative_office',
    },
    async searchUsers() {
        if (this.searchQuery.trim().length < 2) {
            this.results = [];
            this.showResults = false;
            return;
        }

        try {
            const response = await fetch(`${this.searchUrl}?q=${encodeURIComponent(this.searchQuery.trim())}`, {
                headers: { 'Accept': 'application/json' },
            });

            this.results = response.ok ? await response.json() : [];
            this.showResults = this.results.length > 0;
        } catch (error) {
            this.results = [];
            this.showResults = false;
        }
    },
    selectUser(user) {
        this.email = user.email || '';
        this.studentId = user.student_id_number || '';
        this.name = user.name || '';
        this.searchQuery = user.email || user.student_id_number || user.name || '';
        this.userLocked = true;
        this.accountMode = 'search';
        this.showResults = false;
    },
    clearUser() {
        this.email = '';
        this.studentId = '';
        this.name = '';
        this.searchQuery = '';
        this.userLocked = false;
        this.results = [];
        this.showResults = false;
    },
    setAccountMode(mode) {
        this.accountMode = mode;
        this.clearUser();
    },
    prepareSubmit() {
        if (this.accountMode !== 'search' || this.userLocked) {
            return;
        }

        const query = this.searchQuery.trim();

        if (!query || this.email || this.studentId) {
            return;
        }

        if (query.includes('@')) {
            this.email = query;
        } else {
            this.studentId = query;
        }
    },
    userInitial() {
        const source = (this.name || this.email || '?').trim();
        return source.charAt(0).toUpperCase();
    },
    scopeSummary() {
        if (this.role === 'USG Admin') return 'USG organization scope';
        if (this.role === 'LSG Admin') return 'College LSG scope';
        if (this.role === 'Society Admin') return 'Society or non-academic organization scope';
        if (this.role === 'Academic Unit Admin') return 'Academic unit organization scope';
        if (this.role === 'Office Event Admin') return 'Administrative office event scope';

        return 'Assignment scope';
    },
    roleUsesCollege() {
        return ['LSG Admin', 'Academic Unit Admin'].includes(this.role);
    },
    roleUsesOrganization() {
        return Object.keys(this.roleTypes).includes(this.role);
    },
    filteredOrganizations() {
        if (!this.roleUsesOrganization()) return [];

        const targetTypes = Array.isArray(this.roleTypes[this.role])
            ? this.roleTypes[this.role]
            : [this.roleTypes[this.role]];

        return this.organizations.filter((org) => {
            if (!targetTypes.includes(org.type)) return false;
            if (this.roleUsesCollege() && this.collegeId) return org.college_id === this.collegeId;
            if (this.roleUsesCollege() && !this.collegeId) return false;
            return true;
        });
    },
    organizationPlaceholder() {
        if (!this.role) return 'Select role first';
        if (!this.roleUsesOrganization()) return 'No organization scope';
        if (this.roleUsesCollege() && !this.collegeId) return 'Select college first';
        return 'Select organization';
    },
    syncRole() {
        if (!this.roleUsesCollege()) this.collegeId = '';

        if (!this.roleUsesOrganization()) {
            this.organizationId = '';
            return;
        }

        this.syncOrganization();
    },
    syncCollege() {
        this.syncOrganization();
    },
    syncOrganization() {
        if (!this.filteredOrganizations().some((org) => org.id === this.organizationId)) {
            this.organizationId = '';
        }
    },
    chooseOrganization() {
        const org = this.organizations.find((item) => item.id === this.organizationId);

        if (org?.college_id && this.roleUsesCollege()) {
            this.collegeId = org.college_id;
        }
    },
    init() {
        this.syncRole();
    },
}));

/* ─── Admin Access Organization Form ─────────────────────── */
Alpine.data('organizationForm', (config) => ({
    colleges: config.colleges || [],
    type: config.type || 'society',
    collegeId: config.collegeId || '',
    programIds: (config.programIds || []).map((id) => String(id)),
    programSearch: '',
    programsForCollege() {
        const college = this.colleges.find((item) => item.id === this.collegeId);
        return college?.programs || [];
    },
    filteredProgramsForCollege() {
        const term = this.programSearch.trim().toLowerCase();

        if (!term) {
            return this.programsForCollege();
        }

        return this.programsForCollege().filter((program) => {
            return [program.code, program.name]
                .some((value) => String(value || '').toLowerCase().includes(term));
        });
    },
    selectedProgramCount() {
        return this.programIds.length;
    },
    selectAllPrograms() {
        this.programIds = this.programsForCollege().map((program) => String(program.id));
    },
    clearPrograms() {
        this.programIds = [];
    },
    syncProgramSelection() {
        if (!this.typeUsesPrograms()) {
            this.programIds = [];
            return;
        }

        const allowedProgramIds = new Set(this.programsForCollege().map((program) => String(program.id)));
        this.programIds = this.programIds
            .map((id) => String(id))
            .filter((id) => allowedProgramIds.has(id));
    },
    typeShowsCollege() {
        return ['society', 'non_academic_org', 'lsg', 'academic_unit'].includes(this.type);
    },
    typeRequiresCollege() {
        return ['society', 'lsg', 'academic_unit'].includes(this.type);
    },
    typeUsesCollege() {
        return this.typeShowsCollege();
    },
    typeUsesPrograms() {
        return ['society', 'non_academic_org'].includes(this.type);
    },
    init() {
        this.syncProgramSelection();

        this.$watch('collegeId', () => {
            this.programSearch = '';
            this.syncProgramSelection();
        });

        this.$watch('type', () => {
            if (!this.typeShowsCollege()) {
                this.collegeId = '';
            }

            this.programSearch = '';
            this.syncProgramSelection();
        });
    },
}));

/* ─── Participant Scope Audience Builder ─────────────────── */
Alpine.data('participantAudienceBuilder', (config) => ({
    audienceMode: config.audienceMode,
    selectedCollegeIds: config.selectedCollegeIds,
    selectedOrganizationIds: config.selectedOrganizationIds,
    collegeQuery: config.collegeQuery,
    organizationQuery: config.organizationQuery,
    captureMode: config.captureMode,
    ppaRequired: config.ppaRequired,
    analyticsRollupEnabled: config.analyticsRollupEnabled,
    colleges: config.colleges || [],
    organizations: config.organizations || [],
    setAudienceMode(mode) {
        if (!['all', 'college', 'organization'].includes(mode)) {
            return;
        }

        this.audienceMode = mode;

        if (mode !== 'college') {
            this.selectedCollegeIds = [];
            this.collegeQuery = '';
        }

        if (mode !== 'organization') {
            this.selectedOrganizationIds = [];
            this.organizationQuery = '';
        }
    },
    optionMatches(option, query, fields) {
        const term = String(query || '').trim().toLowerCase();

        if (!term) {
            return true;
        }

        return fields.some((field) => String(option?.[field] || '').toLowerCase().includes(term));
    },
    filteredColleges() {
        return this.colleges.filter((college) => this.optionMatches(college, this.collegeQuery, ['code', 'name']));
    },
    filteredOrganizations() {
        return this.organizations.filter((organization) => this.optionMatches(organization, this.organizationQuery, ['acronym', 'name', 'college_code', 'college_name']));
    },
    selectionCount() {
        if (this.audienceMode === 'all') {
            return this.colleges.length;
        }

        if (this.audienceMode === 'college') {
            return this.selectedCollegeIds.length;
        }

        return this.selectedOrganizationIds.length;
    },
    selectionLabel() {
        const count = this.selectionCount();

        if (this.audienceMode === 'all') {
            return `${count} College ${count === 1 ? 'scope' : 'scopes'}`;
        }

        return count === 0 ? 'No audience selected' : `${count} selected`;
    },
    submitLabel() {
        const count = this.selectionCount();

        return count > 1 ? `Add ${count} scopes` : 'Add Scope';
    },
}));

/* ─── Dependent Dropdowns (College → Department → Program → Organization) ───────── */
Alpine.data('dependentSelect', (
    departmentsJson = '{}',
    programsJson = '{}',
    orgsJson = '{}',
    initialCollege = '',
    initialDepartment = '',
    initialProgram = '',
    initialOrg = ''
) => ({
    allDepartments: typeof departmentsJson === 'string' ? JSON.parse(departmentsJson) : departmentsJson,
    allPrograms: typeof programsJson === 'string' ? JSON.parse(programsJson) : programsJson,
    allOrgs: typeof orgsJson === 'string' ? JSON.parse(orgsJson) : orgsJson,
    selectedCollege: initialCollege || '',
    selectedDepartment: initialDepartment || '',
    selectedProgram: initialProgram || '',
    selectedOrg: initialOrg || '',
    filteredDepartments: [],
    filteredPrograms: [],
    filteredOrgs: [],
    init() {
        this.filteredDepartments = this.allDepartments[this.selectedCollege] || [];
        this.filteredPrograms = this.allPrograms[this.selectedDepartment] || [];
        this.filteredOrgs = this.allOrgs[this.selectedProgram] || [];
        this.$watch('selectedCollege', (val) => {
            this.filteredDepartments = this.allDepartments[val] || [];
            this.filteredPrograms = [];
            this.filteredOrgs = [];
            this.selectedDepartment = '';
            this.selectedProgram = '';
            this.selectedOrg = '';
        });
        this.$watch('selectedDepartment', (val) => {
            this.filteredPrograms = this.allPrograms[val] || [];
            this.filteredOrgs = [];
            this.selectedProgram = '';
            this.selectedOrg = '';
        });
        this.$watch('selectedProgram', (val) => {
            this.filteredOrgs = this.allOrgs[val] || [];
            this.selectedOrg = '';
        });
    }
}));

/* ─── Confirm Dialog ─────────────────────────────────────── */
Alpine.data('confirmAction', () => ({
    showConfirm: false,
    confirmMessage: '',
    confirmForm: null,
    askConfirm(message, formEl) {
        this.confirmMessage = message;
        this.confirmForm = formEl;
        this.showConfirm = true;
    },
    doConfirm() {
        if (this.confirmForm) this.confirmForm.submit();
        this.showConfirm = false;
    },
    cancelConfirm() {
        this.showConfirm = false;
        this.confirmForm = null;
    }
}));

Livewire.start();
