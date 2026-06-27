import './bootstrap';
import Alpine from 'alpinejs';
import { Html5Qrcode } from 'html5-qrcode';

window.Alpine = Alpine;

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

/* ─── Dynamic Event Day Rows ─────────────────────────────── */
Alpine.data('eventDaysForm', (initialDays = []) => ({
    days: Array.isArray(initialDays) && initialDays.length
        ? initialDays.map((day, index) => ({
            day_number: index + 1,
            date: day.date || '',
            start_time: day.start_time || '',
            end_time: day.end_time || ''
        }))
        : [{ day_number: 1, date: '', start_time: '', end_time: '' }],
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

Alpine.start();
