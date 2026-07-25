import './bootstrap';
import { Livewire, Alpine } from '../../vendor/livewire/livewire/dist/livewire.esm';
import { Html5Qrcode } from 'html5-qrcode';

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
    },
    blankDay() {
        return { date: '', start_time: '', end_time: '' };
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
                end_time: previous.end_time || ''
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
        });
    },
    syncDayTime(index, field) {
        if (!this.sameTimeForAllDays || !this.days[index]) {
            return;
        }

        const value = this.days[index][field] || '';
        this.days.forEach(day => day[field] = value);
    },
    dayError(index, field) {
        const messages = this.errors[`eventDays.${index}.${field}`];

        return Array.isArray(messages) ? messages[0] : (messages || '');
    },
    clearScheduleErrors() {
        this.errors = Object.fromEntries(
            Object.entries(this.errors).filter(([key]) => !key.startsWith('eventDays.'))
        );
    }
}));

/* ─── Dependent Dropdowns (College → Organization) ───────── */
Alpine.data('dependentSelect', (orgsJson = '{}', programsJson = '{}', initialCollege = '', initialProgram = '', initialOrg = '') => ({
    allOrgs: typeof orgsJson === 'string' ? JSON.parse(orgsJson) : orgsJson,
    allPrograms: typeof programsJson === 'string' ? JSON.parse(programsJson) : programsJson,
    selectedCollege: initialCollege || '',
    selectedProgram: initialProgram || '',
    selectedOrg: initialOrg || '',
    filteredOrgs: [],
    filteredPrograms: [],
    init() {
        this.filteredOrgs = this.allOrgs[this.selectedCollege] || [];
        this.filteredPrograms = this.allPrograms[this.selectedCollege] || [];
        this.$watch('selectedCollege', (val) => {
            this.filteredOrgs = this.allOrgs[val] || [];
            this.filteredPrograms = this.allPrograms[val] || [];
            this.selectedProgram = '';
            this.selectedOrg = '';
        });
    }
}));

/* ─── Student Profile QR Capture ─────────────────────────── */
Alpine.data('profileQrScanner', (initialValue = '') => ({
    mode: 'scanner',
    scannedValue: initialValue || '',
    scanning: false,
    cameraError: '',
    readerId: '',
    scanner: null,
    init() {
        this.readerId = `qr-reader-${Math.random().toString(36).slice(2)}`;
        this.$nextTick(() => {
            if (this.$refs.reader) {
                this.$refs.reader.id = this.readerId;
            }
        });
    },
    async startScanner() {
        this.cameraError = '';
        if (!this.readerId) return;

        this.scanner = new Html5Qrcode(this.readerId);

        try {
            await this.scanner.start(
                { facingMode: 'environment' },
                { fps: 10, qrbox: { width: 250, height: 250 } },
                async (decodedText) => {
                    this.scannedValue = decodedText;
                    await this.stopScanner();
                },
                () => {}
            );
            this.scanning = true;
        } catch (error) {
            this.cameraError = 'Camera access failed. Use manual entry instead.';
            this.mode = 'manual';
            this.scanning = false;
        }
    },
    async stopScanner() {
        if (!this.scanner) {
            this.scanning = false;
            return;
        }

        try {
            await this.scanner.stop();
            await this.scanner.clear();
        } catch (error) {
            // The library throws if stop is called before a camera stream starts.
        }

        this.scanner = null;
        this.scanning = false;
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
