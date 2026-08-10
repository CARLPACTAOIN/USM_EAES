@php
    $eventData = $analytics['event'];
    $attendance = $analytics['attendance'];
    $evaluations = $analytics['evaluations'];
    $dailyAttendance = collect($analytics['daily_attendance']);
    $scopeRollups = collect($analytics['participant_scopes']);
    $scannerSync = $analytics['scanner_sync'];
    $comments = collect($analytics['sentiment_comments']);
    $demographics = collect($analytics['demographics']);
    $yearLevels = collect($analytics['year_levels'] ?? []);
    $categoryAverages = collect($evaluations['category_averages']);
    $label = fn ($value) => \Illuminate\Support\Str::of($value ?: 'N/A')->replace('_', ' ')->replace('-', ' ')->title();
    $rate = fn ($value) => min(100, max(0, (float) $value));
    $tone = function (?string $value): string {
        return match ($value) {
            'active', 'approved', 'present_on_time', 'valid', 'submitted', 'positive', 'healthy', 'completed' => 'good',
            'late', 'late_cutoff', 'left_early', 'pending', 'neutral', 'mixed', 'evaluation_open', 'processing', 'scheduled' => 'warn',
            'expired', 'revoked', 'absent', 'invalid', 'missing', 'negative', 'needs_review', 'evaluation_closed' => 'danger',
            default => 'neutral',
        };
    };
    $toneClass = fn (?string $value) => 'analytics-tone-'.$tone($value);
    $tabs = [
        'overview' => ['label' => 'Overview', 'icon' => 'grid'],
        'attendance' => ['label' => 'Attendance', 'icon' => 'check'],
        'evaluations' => ['label' => 'Evaluations', 'icon' => 'star'],
        'sentiment' => ['label' => 'Sentiment', 'icon' => 'chat'],
        'scopes' => ['label' => 'Participant Scopes', 'icon' => 'layers'],
        'scanner' => ['label' => 'Scanner & Sync', 'icon' => 'device'],
        'exports' => ['label' => 'Exports', 'icon' => 'download'],
    ];
    $sentimentCounts = collect(['positive', 'neutral', 'negative', 'unprocessed'])
        ->mapWithKeys(fn ($key) => [$key => (int) ($evaluations['sentiments'][$key] ?? 0)]);
    $chartPayload = [
        'demographics' => [
            'labels' => $demographics->pluck('program_code')->values(),
            'series' => $demographics->pluck('count')->map(fn ($value) => (int) $value)->values(),
        ],
        'yearLevels' => [
            'labels' => $yearLevels->pluck('label')->values(),
            'series' => $yearLevels->pluck('count')->map(fn ($value) => (int) $value)->values(),
        ],
        'attendanceTrend' => [
            'labels' => $dailyAttendance->pluck('label')->values(),
            'attendance' => $dailyAttendance->pluck('attendance_rate')->map(fn ($value) => (float) $value)->values(),
            'valid' => $dailyAttendance->pluck('valid_attendance_rate')->map(fn ($value) => (float) $value)->values(),
        ],
        'evaluationRate' => [(float) $rate($evaluations['response_rate_attended'])],
        'categoryAverages' => [
            'labels' => $categoryAverages->pluck('label')->values(),
            'series' => $categoryAverages->pluck('average')->map(fn ($value) => (float) $value)->values(),
        ],
        'scoreDistribution' => [
            'labels' => $categoryAverages->pluck('label')->values(),
            'series' => collect(range(1, 5))->map(fn ($score) => [
                'name' => (string) $score,
                'data' => $categoryAverages->map(fn ($category) => (int) ($category['distribution'][$score] ?? 0))->values(),
            ])->values(),
        ],
        'sentiments' => [
            'labels' => ['Positive', 'Neutral', 'Negative', 'Unprocessed'],
            'series' => $sentimentCounts->values(),
        ],
        'sentimentHealth' => [(float) $rate(100 - ($evaluations['sentiment_health']['negative_rate'] ?? 0))],
    ];
@endphp

@push('styles')
<style>
    .analytics-workspace {
        color: #102018;
    }

    .analytics-hero {
        display: grid;
        gap: 1rem;
        padding-bottom: 1rem;
        border-bottom: 1px solid rgba(14, 93, 54, 0.12);
    }

    @media (min-width: 1024px) {
        .analytics-hero {
            grid-template-columns: minmax(0, 1fr) auto;
            align-items: start;
        }
    }

    .analytics-breadcrumb {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 0.5rem;
        margin-bottom: 0.625rem;
        color: #647568;
        font-size: 0.75rem;
        font-weight: 600;
    }

    .analytics-breadcrumb a {
        color: #526258;
        transition: color 150ms ease;
    }

    .analytics-breadcrumb a:hover {
        color: #0e5d36;
    }

    .analytics-title {
        margin: 0;
        color: #102018;
        font-size: clamp(1.5rem, 1.1rem + 1vw, 2rem);
        font-weight: 800;
        line-height: 1.15;
        letter-spacing: 0;
    }

    .analytics-meta-row {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
        margin-top: 0.75rem;
    }

    .analytics-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
    }

    .analytics-stat-card {
        position: relative;
        overflow: hidden;
        min-height: 10rem;
        border: 1px solid color-mix(in srgb, var(--accent, #0e5d36) 28%, rgba(15, 23, 42, 0.08));
        border-radius: 8px;
        background:
            linear-gradient(135deg, color-mix(in srgb, var(--accent, #0e5d36) 10%, transparent), transparent 62%),
            rgba(255, 255, 255, 0.98);
        box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04), 0 16px 34px rgba(15, 23, 42, 0.06);
    }

    .analytics-stat-card::before {
        content: "";
        position: absolute;
        inset: 0 auto 0 0;
        width: 4px;
        background: var(--accent, #0e5d36);
    }

    .analytics-stat-body {
        display: grid;
        gap: 0.875rem;
        padding: 1rem;
    }

    .analytics-stat-top {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.75rem;
    }

    .analytics-stat-label {
        color: #647568;
        font-size: 0.75rem;
        font-weight: 800;
        text-transform: uppercase;
    }

    .analytics-stat-icon,
    .analytics-context-icon,
    .analytics-export-icon {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 2.25rem;
        height: 2.25rem;
        border-radius: 8px;
        color: var(--accent, #0e5d36);
        background: color-mix(in srgb, var(--accent, #0e5d36) 12%, white);
        border: 1px solid color-mix(in srgb, var(--accent, #0e5d36) 20%, transparent);
        flex: 0 0 auto;
    }

    .analytics-stat-value {
        display: flex;
        align-items: baseline;
        gap: 0.5rem;
        color: #102018;
    }

    .analytics-stat-number {
        font-family: var(--font-mono);
        font-size: 1.875rem;
        line-height: 1;
        font-weight: 800;
        letter-spacing: 0;
    }

    .analytics-stat-support {
        color: #647568;
        font-size: 0.75rem;
        line-height: 1.45;
    }

    .analytics-progress {
        height: 0.5rem;
        overflow: hidden;
        border-radius: 9999px;
        background: #e5eee8;
    }

    .analytics-progress-fill {
        width: var(--bar-width, 0%);
        height: 100%;
        border-radius: inherit;
        background: var(--accent, #0e5d36);
    }

    .analytics-tabs {
        overflow-x: auto;
        padding: 0.25rem;
        border: 1px solid rgba(15, 23, 42, 0.08);
        border-radius: 8px;
        background: #eef5f0;
    }

    .analytics-tab-list {
        display: flex;
        min-width: max-content;
        gap: 0.25rem;
    }

    .analytics-tab {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        min-height: 2.5rem;
        padding: 0.5rem 0.75rem;
        border: 1px solid transparent;
        border-radius: 6px;
        color: #526258;
        font-size: 0.8125rem;
        font-weight: 800;
        background: transparent;
        cursor: pointer;
        transition: background-color 160ms ease, color 160ms ease, border-color 160ms ease, box-shadow 160ms ease;
    }

    .analytics-tab:hover {
        color: #102018;
        background: rgba(255, 255, 255, 0.72);
    }

    .analytics-tab.is-active {
        color: #0e5d36;
        border-color: rgba(14, 93, 54, 0.16);
        background: #ffffff;
        box-shadow: 0 1px 2px rgba(15, 23, 42, 0.05);
    }

    .analytics-panel-grid {
        display: grid;
        gap: 1rem;
    }

    @media (min-width: 1024px) {
        .analytics-panel-grid-3 {
            grid-template-columns: minmax(0, 2fr) minmax(18rem, 1fr);
        }

        .analytics-panel-grid-2 {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    .analytics-card {
        border: 1px solid rgba(15, 23, 42, 0.08);
        border-radius: 8px;
        background: rgba(255, 255, 255, 0.98);
        box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04), 0 16px 34px rgba(15, 23, 42, 0.06);
        overflow: hidden;
    }

    .analytics-card-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        padding: 1rem 1.25rem;
        border-bottom: 1px solid rgba(15, 23, 42, 0.08);
        background: linear-gradient(180deg, #ffffff 0%, #fbfdfb 100%);
    }

    .analytics-card-header--filters {
        align-items: flex-start;
        flex-wrap: wrap;
    }

    .analytics-card-heading-stack {
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        gap: 0.625rem 0.75rem;
        min-width: 0;
    }

    .analytics-card-title {
        margin: 0;
        color: #102018;
        font-size: 0.95rem;
        font-weight: 800;
        line-height: 1.25;
        letter-spacing: 0;
    }

    .analytics-card-kicker {
        color: #647568;
        font-size: 0.75rem;
        font-weight: 700;
    }

    .analytics-card-body {
        padding: 1rem;
    }

    @media (min-width: 768px) {
        .analytics-card-body {
            padding: 1.25rem;
        }
    }

    .analytics-context-list {
        display: grid;
        gap: 0.75rem;
    }

    .analytics-context-row {
        display: grid;
        grid-template-columns: 2.25rem minmax(0, 1fr);
        gap: 0.75rem;
        align-items: start;
        padding: 0.75rem;
        border: 1px solid rgba(15, 23, 42, 0.08);
        border-radius: 8px;
        background: #f7faf8;
    }

    .analytics-context-label {
        color: #647568;
        font-size: 0.72rem;
        font-weight: 800;
        text-transform: uppercase;
    }

    .analytics-context-value {
        margin-top: 0.125rem;
        color: #102018;
        font-size: 0.875rem;
        font-weight: 700;
        line-height: 1.35;
    }

    .analytics-chip {
        display: inline-flex;
        align-items: center;
        gap: 0.375rem;
        min-height: 1.75rem;
        padding: 0.25rem 0.625rem;
        border: 1px solid var(--tone-border, rgba(100, 116, 139, 0.22));
        border-radius: 9999px;
        color: var(--tone-text, #475569);
        background: var(--tone-bg, #f1f5f9);
        font-size: 0.75rem;
        font-weight: 800;
        line-height: 1;
        white-space: nowrap;
    }

    .analytics-chip-dot {
        width: 0.5rem;
        height: 0.5rem;
        border-radius: 9999px;
        background: var(--tone-dot, currentColor);
    }

    .analytics-tone-good {
        --tone-text: #166534;
        --tone-bg: #dcfce7;
        --tone-border: rgba(22, 101, 52, 0.22);
        --tone-dot: #16a34a;
    }

    .analytics-tone-warn {
        --tone-text: #92400e;
        --tone-bg: #fef3c7;
        --tone-border: rgba(146, 64, 14, 0.22);
        --tone-dot: #f59e0b;
    }

    .analytics-tone-danger {
        --tone-text: #991b1b;
        --tone-bg: #fee2e2;
        --tone-border: rgba(153, 27, 27, 0.22);
        --tone-dot: #ef4444;
    }

    .analytics-tone-neutral {
        --tone-text: #475569;
        --tone-bg: #f1f5f9;
        --tone-border: rgba(71, 85, 105, 0.2);
        --tone-dot: #64748b;
    }

    .analytics-chart {
        position: relative;
        min-height: 16rem;
        width: 100%;
    }

    .analytics-chart-short {
        min-height: 13rem;
    }

    .analytics-chart-loading {
        position: absolute;
        inset: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.625rem;
        color: #526258;
        font-size: 0.8125rem;
        font-weight: 700;
        background: rgba(255, 255, 255, 0.86);
    }

    .analytics-chart-loading-spinner {
        width: 1.125rem;
        height: 1.125rem;
        border: 2px solid rgba(14, 93, 54, 0.2);
        border-top-color: #0e5d36;
        border-radius: 9999px;
        animation: analytics-chart-spin 700ms linear infinite;
    }

    @keyframes analytics-chart-spin {
        to {
            transform: rotate(360deg);
        }
    }

    .analytics-table-wrap {
        overflow-x: auto;
    }

    .analytics-table {
        width: 100%;
        min-width: 44rem;
        border-collapse: collapse;
        font-size: 0.875rem;
    }

    .analytics-table th {
        padding: 0.75rem 1rem;
        color: #526258;
        background: #f4f8f5;
        border-bottom: 1px solid rgba(14, 93, 54, 0.12);
        font-size: 0.7rem;
        font-weight: 800;
        text-align: left;
        text-transform: uppercase;
        white-space: nowrap;
    }

    .analytics-table td {
        padding: 0.875rem 1rem;
        border-bottom: 1px solid rgba(15, 23, 42, 0.08);
        color: #102018;
        vertical-align: top;
    }

    .analytics-table tbody tr:hover {
        background: #f8fbf8;
    }

    .analytics-table .text-right {
        text-align: right;
    }

    .analytics-mini-bar {
        display: grid;
        gap: 0.375rem;
        min-width: 10rem;
    }

    .analytics-mini-bar-top {
        display: flex;
        justify-content: space-between;
        gap: 0.5rem;
        color: #526258;
        font-size: 0.75rem;
        font-weight: 700;
    }

    .analytics-filter-grid {
        display: grid;
        grid-template-columns: repeat(1, minmax(0, 1fr));
        gap: 0.875rem;
    }

    .analytics-filter-titlebar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.75rem;
    }

    .analytics-filter-title {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        color: #526258;
        font-size: 0.72rem;
        font-weight: 800;
        line-height: 1.2;
        text-transform: uppercase;
        letter-spacing: 0;
    }

    .analytics-filter-toolbar {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        min-width: 0;
    }

    .analytics-filter-field {
        display: grid;
        gap: 0.35rem;
        min-width: 0;
    }

    .analytics-filter-label {
        color: #526258;
        font-size: 0.7rem;
        font-weight: 800;
        line-height: 1.2;
        text-transform: uppercase;
        letter-spacing: 0;
    }

    .analytics-filter-control {
        display: block;
        width: 100%;
        min-height: 2.65rem;
        padding: 0.5rem 0.75rem;
        border: 1px solid #d7e2db;
        border-radius: 8px;
        color: #102018;
        background-color: #ffffff;
        font-size: 0.84rem;
    }

    select.analytics-filter-control {
        padding-right: 2.5rem;
    }

    .analytics-filter-control:focus {
        border-color: #0e5d36;
        box-shadow: 0 0 0 3px rgba(14, 93, 54, 0.14);
        outline: none;
    }

    .analytics-filter-control--rows {
        min-width: 7.25rem;
        min-height: 2.35rem;
        font-size: 0.8rem;
    }

    .analytics-filter-search {
        position: relative;
    }

    .analytics-filter-search-icon {
        position: absolute;
        top: 50%;
        left: 0.85rem;
        width: 1rem;
        height: 1rem;
        color: #6f8076;
        transform: translateY(-50%);
        pointer-events: none;
    }

    .analytics-filter-search-input {
        padding-left: 2.4rem;
    }

    @media (min-width: 768px) {
        .analytics-filter-grid {
            grid-template-columns: minmax(16rem, 1.5fr) repeat(2, minmax(10rem, 1fr));
            align-items: end;
        }

        .analytics-filter-field--search {
            grid-column: span 2;
        }
    }

    @media (min-width: 1180px) {
        .analytics-filter-grid {
            grid-template-columns: minmax(18rem, 1.55fr) repeat(5, minmax(8.75rem, 1fr));
        }

        .analytics-filter-field--search {
            grid-column: auto;
        }
    }

    .analytics-export-grid {
        display: grid;
        gap: 0.75rem;
    }

    @media (min-width: 768px) {
        .analytics-export-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    .analytics-export-card {
        display: grid;
        grid-template-columns: 2.5rem minmax(0, 1fr);
        gap: 0.875rem;
        align-items: start;
        padding: 1rem;
        border: 1px solid rgba(15, 23, 42, 0.08);
        border-radius: 8px;
        background: #f7faf8;
        transition: border-color 160ms ease, box-shadow 160ms ease, transform 160ms ease;
    }

    .analytics-export-card:hover {
        border-color: rgba(14, 93, 54, 0.24);
        box-shadow: 0 10px 24px rgba(15, 23, 42, 0.08);
        transform: translateY(-1px);
    }

    .analytics-empty {
        padding: 1.5rem;
        color: #647568;
        font-size: 0.875rem;
        text-align: center;
    }

    @media (prefers-reduced-motion: reduce) {
        .analytics-tab,
        .analytics-export-card,
        .analytics-breadcrumb a {
            transition: none;
        }

        .analytics-export-card:hover {
            transform: none;
        }
    }
</style>
@endpush

@push('scripts')
<script>
    window.eventAnalyticsPage = function () {
        const validTabs = ['overview', 'attendance', 'evaluations', 'sentiment', 'scopes', 'scanner', 'exports'];

        return {
            activeTab: 'overview',
            init() {
                const requested = window.location.hash ? window.location.hash.substring(1) : new URLSearchParams(window.location.search).get('tab');
                this.activeTab = validTabs.includes(requested) ? requested : 'overview';

                this.$nextTick(() => {
                    window.dispatchEvent(new CustomEvent('eaes-analytics-tab-change', { detail: { tab: this.activeTab } }));
                });

                window.addEventListener('popstate', () => {
                    const hashTab = window.location.hash ? window.location.hash.substring(1) : 'overview';
                    this.activeTab = validTabs.includes(hashTab) ? hashTab : 'overview';
                    window.dispatchEvent(new CustomEvent('eaes-analytics-tab-change', { detail: { tab: this.activeTab } }));
                });
            },
            setTab(tab) {
                if (!validTabs.includes(tab)) {
                    return;
                }

                this.activeTab = tab;
                history.pushState(null, '', '#' + tab);
                this.$nextTick(() => window.dispatchEvent(new CustomEvent('eaes-analytics-tab-change', { detail: { tab } })));
            }
        };
    };

    (function () {
        window.eaesEventAnalyticsRuntime?.destroy();

        const payload = @js($chartPayload);
        const chartSource = @js(asset('vendor/apexcharts/apexcharts.min.js'));
        const charts = new Map();
        const cleanupCallbacks = [];
        let destroyed = false;
        let apexChartsPromise = window.eaesApexChartsPromise;
        const palette = {
            green: '#0e5d36',
            blue: '#2563eb',
            amber: '#d97706',
            indigo: '#4f46e5',
            red: '#dc2626',
            slate: '#64748b'
        };

        function textColor() {
            return getComputedStyle(document.body).getPropertyValue('--color-text-secondary').trim() || '#526258';
        }

        function isDarkMode() {
            return document.documentElement.classList.contains('dark') ||
                document.body.classList.contains('dark') ||
                window.matchMedia('(prefers-color-scheme: dark)').matches;
        }

        function baseOptions() {
            return {
                chart: {
                    toolbar: { show: false },
                    fontFamily: 'Inter, sans-serif',
                    foreColor: textColor(),
                    animations: { enabled: !window.matchMedia('(prefers-reduced-motion: reduce)').matches }
                },
                dataLabels: { style: { fontFamily: 'Inter, sans-serif' } },
                grid: { borderColor: 'rgba(15, 23, 42, 0.08)' },
                noData: { text: 'No data available' },
                tooltip: { theme: isDarkMode() ? 'dark' : 'light' }
            };
        }

        function addListener(target, event, callback, options) {
            target.addEventListener(event, callback, options);
            cleanupCallbacks.push(() => target.removeEventListener(event, callback, options));
        }

        function renderChart(id, options) {
            const element = document.getElementById(id);
            if (!element || !window.ApexCharts || charts.has(id)) {
                return;
            }

            const chart = new ApexCharts(element, { ...baseOptions(), ...options });
            charts.set(id, chart);
            chart.render()
                .then(() => setChartLoading(id, false))
                .catch(() => {
                    charts.delete(id);
                    markChartsUnavailable([id]);
                });
        }

        function setChartLoading(id, loading) {
            const element = document.getElementById(id);
            if (!element) {
                return;
            }

            let indicator = element.querySelector('.analytics-chart-loading');

            if (loading) {
                element.setAttribute('aria-busy', 'true');

                if (!indicator) {
                    indicator = document.createElement('div');
                    indicator.className = 'analytics-chart-loading';
                    indicator.setAttribute('role', 'status');
                    indicator.innerHTML = '<span class="analytics-chart-loading-spinner" aria-hidden="true"></span><span>Loading chart</span>';
                    element.appendChild(indicator);
                }

                return;
            }

            element.setAttribute('aria-busy', 'false');
            indicator?.remove();
        }

        function markChartsLoading(chartIds) {
            chartIds
                .filter((id) => !charts.has(id))
                .forEach((id) => setChartLoading(id, true));
        }

        function markChartsUnavailable(chartIds) {
            chartIds.forEach((id) => {
                const element = document.getElementById(id);
                if (!element) {
                    return;
                }

                setChartLoading(id, false);
                element.innerHTML = '<p class="analytics-empty">Charts are unavailable. The table data remains available below.</p>';
            });
        }

        const chartGroups = {
            overview: () => {
                renderChart('demographicsChart', {
                    series: [{ name: 'Attendees', data: payload.demographics.series }],
                    chart: { ...baseOptions().chart, type: 'bar', height: 300 },
                    colors: [palette.green],
                    plotOptions: { bar: { horizontal: true, borderRadius: 4, dataLabels: { position: 'right' } } },
                    xaxis: { categories: payload.demographics.labels, labels: { formatter: (value) => Math.round(value) } },
                    dataLabels: { enabled: true },
                });

                renderChart('yearLevelChart', {
                    series: payload.yearLevels.series.length ? payload.yearLevels.series : [0],
                    chart: { ...baseOptions().chart, type: 'donut', height: 300 },
                    colors: [palette.green, palette.amber, palette.blue, palette.indigo, '#0f766e', '#64748b'],
                    labels: payload.yearLevels.labels.length ? payload.yearLevels.labels : ['No data'],
                    legend: { position: 'bottom' },
                    dataLabels: { enabled: true },
                });
            },
            attendance: () => renderChart('attendanceTrendChart', {
                series: [
                    { name: 'Attendance rate', data: payload.attendanceTrend.attendance },
                    { name: 'Valid attendance rate', data: payload.attendanceTrend.valid }
                ],
                chart: { ...baseOptions().chart, type: 'area', height: 300 },
                colors: [palette.green, palette.blue],
                stroke: { curve: 'smooth', width: 3 },
                fill: { type: 'gradient', gradient: { shadeIntensity: 0.2, opacityFrom: 0.3, opacityTo: 0.04 } },
                xaxis: { categories: payload.attendanceTrend.labels },
                yaxis: { min: 0, max: 100, labels: { formatter: (value) => value + '%' } },
                dataLabels: { enabled: false },
            }),
            evaluations: () => {
                renderChart('evaluationRateChart', {
                    series: payload.evaluationRate,
                    chart: { ...baseOptions().chart, type: 'radialBar', height: 230 },
                    colors: [palette.amber],
                    plotOptions: { radialBar: { hollow: { size: '62%' }, dataLabels: { name: { show: true }, value: { formatter: (value) => value + '%' } } } },
                    labels: ['Response'],
                });

                renderChart('categoryAverageChart', {
                    series: [{ name: 'Average', data: payload.categoryAverages.series }],
                    chart: { ...baseOptions().chart, type: 'bar', height: 230 },
                    colors: [palette.blue],
                    plotOptions: { bar: { horizontal: true, borderRadius: 4 } },
                    xaxis: { categories: payload.categoryAverages.labels, min: 0, max: 5 },
                    dataLabels: { enabled: true, formatter: (value) => Number(value).toFixed(2) },
                });

                renderChart('scoreDistributionChart', {
                    series: payload.scoreDistribution.series,
                    chart: { ...baseOptions().chart, type: 'bar', stacked: true, height: 320 },
                    colors: ['#dc2626', '#f97316', '#d97706', '#2563eb', '#0e5d36'],
                    plotOptions: { bar: { horizontal: true, borderRadius: 4 } },
                    xaxis: { categories: payload.scoreDistribution.labels },
                    dataLabels: { enabled: true },
                    legend: { position: 'top' },
                });
            },
            sentiment: () => {
                renderChart('sentimentChart', {
                    series: payload.sentiments.series,
                    chart: { ...baseOptions().chart, type: 'donut', height: 300 },
                    labels: payload.sentiments.labels,
                    colors: [palette.green, palette.amber, palette.red, palette.slate],
                    dataLabels: { enabled: true },
                    legend: { position: 'bottom' },
                });

                renderChart('sentimentHealthChart', {
                    series: payload.sentimentHealth,
                    chart: { ...baseOptions().chart, type: 'radialBar', height: 260 },
                    colors: [palette.indigo],
                    plotOptions: { radialBar: { hollow: { size: '64%' }, dataLabels: { name: { show: true }, value: { formatter: (value) => value + '%' } } } },
                    labels: ['Health'],
                });
            }
        };

        const chartIdsByTab = {
            overview: ['demographicsChart', 'yearLevelChart'],
            attendance: ['attendanceTrendChart'],
            evaluations: ['evaluationRateChart', 'categoryAverageChart', 'scoreDistributionChart'],
            sentiment: ['sentimentChart', 'sentimentHealthChart']
        };

        function loadApexCharts() {
            if (window.ApexCharts) {
                return Promise.resolve();
            }

            if (apexChartsPromise) {
                return apexChartsPromise;
            }

            apexChartsPromise = new Promise((resolve, reject) => {
                const script = document.createElement('script');
                script.src = chartSource;
                script.async = true;
                script.dataset.eaesApexcharts = 'true';
                script.addEventListener('load', () => window.ApexCharts ? resolve() : reject());
                script.addEventListener('error', reject);
                document.head.appendChild(script);
            });
            window.eaesApexChartsPromise = apexChartsPromise;

            return apexChartsPromise;
        }

        function renderChartsForTab(tab) {
            const renderGroup = chartGroups[tab];
            if (!renderGroup || destroyed) {
                return;
            }

            markChartsLoading(chartIdsByTab[tab]);
            loadApexCharts()
                .then(() => {
                    if (destroyed) {
                        return;
                    }

                    renderGroup();
                })
                .catch(() => markChartsUnavailable(chartIdsByTab[tab]));
        }

        function refreshChartTheme() {
            charts.forEach((chart) => chart.updateOptions({
                chart: { foreColor: textColor() },
                tooltip: { theme: isDarkMode() ? 'dark' : 'light' }
            }, false, true));
        }

        function resizeCharts() {
            charts.forEach((chart) => chart.resize());
        }

        function activeTab() {
            const requested = window.location.hash ? window.location.hash.substring(1) : new URLSearchParams(window.location.search).get('tab');

            return Object.hasOwn(chartGroups, requested) ? requested : 'overview';
        }

        function destroy() {
            if (destroyed) {
                return;
            }

            destroyed = true;
            cleanupCallbacks.splice(0).forEach((cleanup) => cleanup());
            charts.forEach((chart) => chart.destroy());
            charts.clear();
            themeObserver.disconnect();
            bodyThemeObserver.disconnect();

            if (window.eaesEventAnalyticsRuntime?.destroy === destroy) {
                delete window.eaesEventAnalyticsRuntime;
            }
        }

        const themeObserver = new MutationObserver(refreshChartTheme);
        const bodyThemeObserver = new MutationObserver(refreshChartTheme);
        themeObserver.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
        bodyThemeObserver.observe(document.body, { attributes: true, attributeFilter: ['class'] });

        addListener(window, 'eaes-analytics-tab-change', (event) => {
            renderChartsForTab(event.detail.tab);
            window.setTimeout(resizeCharts, 60);
        });
        addListener(window, 'resize', resizeCharts);
        addListener(window.matchMedia('(prefers-color-scheme: dark)'), 'change', refreshChartTheme);
        addListener(document, 'livewire:navigating', destroy, { once: true });

        window.eaesEventAnalyticsRuntime = { destroy };
    })();
</script>
@endpush

<x-layouts.app :title="'Event Analytics - '.$event->title">
<div class="analytics-workspace space-y-6"
     x-data="eventAnalyticsPage()"
     x-init="init()">
    <div class="analytics-hero">
        <div class="min-w-0">
            <div class="analytics-breadcrumb">
                <a href="{{ route('dashboard.events') }}">Events</a>
                <span>/</span>
                <a href="{{ route('dashboard.analytics') }}">Organization Analytics</a>
                <span>/</span>
                <span>Event Analytics</span>
            </div>
            <h1 class="analytics-title">{{ $event->title }}</h1>
            <div class="analytics-meta-row">
                <span class="analytics-chip {{ $toneClass($eventData['status']) }}"><span class="analytics-chip-dot"></span>{{ $label($eventData['status']) }}</span>
                <span class="analytics-chip {{ $toneClass($eventData['lifecycle_phase']) }}"><span class="analytics-chip-dot"></span>{{ $label($eventData['lifecycle_phase']) }}</span>
                <span class="analytics-chip analytics-tone-neutral"><span class="analytics-chip-dot"></span>{{ $label($eventData['event_domain']) }}</span>
                <span class="analytics-chip analytics-tone-neutral"><span class="analytics-chip-dot"></span>{{ $eventData['start_date'] }} to {{ $eventData['end_date'] }}</span>
            </div>
        </div>
        <div class="analytics-actions">
            <a href="{{ route('dashboard.events.exports.attendance.pdf', $event) }}" class="btn btn-sm btn-secondary">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5l5 5v11a2 2 0 01-2 2z"/></svg>
                Attendance PDF
            </a>
            <a href="{{ route('dashboard.events.exports.evaluations.excel', $event) }}" class="btn btn-sm btn-secondary">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 4h16v16H4zM8 4v16m8-16v16M4 9h16M4 15h16"/></svg>
                Evaluations XLSX
            </a>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <div class="analytics-stat-card" style="--accent: #0e5d36;">
            <div class="analytics-stat-body">
                <div class="analytics-stat-top">
                    <p class="analytics-stat-label">Attendance Rate</p>
                    <span class="analytics-stat-icon" aria-hidden="true"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 12l2 2 4-4M7 4h10a2 2 0 012 2v14l-7-3-7 3V6a2 2 0 012-2z"/></svg></span>
                </div>
                <div class="analytics-stat-value">
                    <span class="analytics-stat-number" data-value="{{ $attendance['attendance_rate'] }}">{{ $attendance['attendance_rate'] }}%</span>
                    <span class="analytics-stat-support">{{ $attendance['attended'] }} / {{ $attendance['expected_audience'] }}</span>
                </div>
                <div class="analytics-progress" aria-label="Attendance rate {{ $attendance['attendance_rate'] }}%">
                    <div class="analytics-progress-fill" style="--bar-width: {{ $rate($attendance['attendance_rate']) }}%;"></div>
                </div>
                <p class="analytics-stat-support">{{ $attendance['denominator_label'] }}</p>
            </div>
        </div>
        <div class="analytics-stat-card" style="--accent: #2563eb;">
            <div class="analytics-stat-body">
                <div class="analytics-stat-top">
                    <p class="analytics-stat-label">Valid Attendance</p>
                    <span class="analytics-stat-icon" aria-hidden="true"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 3l7 4v5c0 4-2.7 7.7-7 9-4.3-1.3-7-5-7-9V7l7-4zM9 12l2 2 4-4"/></svg></span>
                </div>
                <div class="analytics-stat-value">
                    <span class="analytics-stat-number">{{ $attendance['valid_attendance_rate'] }}%</span>
                    <span class="analytics-stat-support">{{ $attendance['valid_unique_attended'] }} / {{ $attendance['expected_audience'] }}</span>
                </div>
                <div class="analytics-progress" aria-label="Valid attendance rate {{ $attendance['valid_attendance_rate'] }}%">
                    <div class="analytics-progress-fill" style="--bar-width: {{ $rate($attendance['valid_attendance_rate']) }}%;"></div>
                </div>
                <p class="analytics-stat-support">{{ $attendance['invalid_or_pending_unique'] }} attended but not valid yet</p>
            </div>
        </div>
        <div class="analytics-stat-card" style="--accent: #d97706;">
            <div class="analytics-stat-body">
                <div class="analytics-stat-top">
                    <p class="analytics-stat-label">Evaluation Response</p>
                    <span class="analytics-stat-icon" aria-hidden="true"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M11.5 3.5l2.4 4.9 5.4.8-3.9 3.8.9 5.4-4.8-2.5-4.8 2.5.9-5.4-3.9-3.8 5.4-.8 2.4-4.9z"/></svg></span>
                </div>
                <div class="analytics-stat-value">
                    <span class="analytics-stat-number">{{ $evaluations['response_rate_attended'] }}%</span>
                    <span class="analytics-stat-support">{{ $evaluations['unique_evaluators'] }} / {{ $attendance['global_unique_attended'] }}</span>
                </div>
                <div class="analytics-progress" aria-label="Evaluation response rate {{ $evaluations['response_rate_attended'] }}%">
                    <div class="analytics-progress-fill" style="--bar-width: {{ $rate($evaluations['response_rate_attended']) }}%;"></div>
                </div>
                <p class="analytics-stat-support">Attended-to-evaluated funnel</p>
            </div>
        </div>
        <div class="analytics-stat-card" style="--accent: #4f46e5;">
            <div class="analytics-stat-body">
                <div class="analytics-stat-top">
                    <p class="analytics-stat-label">Sentiment Health</p>
                    <span class="analytics-stat-icon" aria-hidden="true"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M7 8h10M7 12h7m-9 8l3-3h9a4 4 0 004-4V8a4 4 0 00-4-4H7a4 4 0 00-4 4v5a4 4 0 004 4"/></svg></span>
                </div>
                <div class="analytics-stat-value">
                    <span class="analytics-stat-number text-xl">{{ $evaluations['sentiment_health']['label'] }}</span>
                </div>
                <div class="analytics-progress" aria-label="Negative sentiment rate {{ $evaluations['sentiment_health']['negative_rate'] ?? 0 }}%">
                    <div class="analytics-progress-fill" style="--bar-width: {{ $rate(100 - ($evaluations['sentiment_health']['negative_rate'] ?? 0)) }}%;"></div>
                </div>
                <p class="analytics-stat-support">{{ $evaluations['sentiments']['negative'] }} negative / {{ $evaluations['total_submitted'] }} submitted</p>
            </div>
        </div>
    </div>

    <nav class="analytics-tabs" aria-label="Event analytics sections">
        <div class="analytics-tab-list">
            @foreach($tabs as $key => $tab)
                <button type="button"
                        class="analytics-tab"
                        :class="activeTab === '{{ $key }}' ? 'is-active' : ''"
                        @click="setTab('{{ $key }}')">
                    @switch($tab['icon'])
                        @case('grid')
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 4h7v7H4zM13 4h7v7h-7zM4 13h7v7H4zM13 13h7v7h-7z"/></svg>
                            @break
                        @case('check')
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 12l2 2 4-4M5 5h14v14H5z"/></svg>
                            @break
                        @case('star')
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M11.5 3.5l2.4 4.9 5.4.8-3.9 3.8.9 5.4-4.8-2.5-4.8 2.5.9-5.4-3.9-3.8 5.4-.8 2.4-4.9z"/></svg>
                            @break
                        @case('chat')
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M7 8h10M7 12h6m-8 8l3-3h9a4 4 0 004-4V8a4 4 0 00-4-4H7a4 4 0 00-4 4v5a4 4 0 004 4"/></svg>
                            @break
                        @case('layers')
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 4l8 4-8 4-8-4 8-4zM4 12l8 4 8-4M4 16l8 4 8-4"/></svg>
                            @break
                        @case('device')
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M8 2h8a2 2 0 012 2v16a2 2 0 01-2 2H8a2 2 0 01-2-2V4a2 2 0 012-2zm3 17h2"/></svg>
                            @break
                        @default
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 3v12m0 0l-4-4m4 4l4-4M5 21h14"/></svg>
                    @endswitch
                    <span>{{ $tab['label'] }}</span>
                </button>
            @endforeach
        </div>
    </nav>

    <section x-show="activeTab === 'overview'" x-cloak class="space-y-5">
        <div class="analytics-panel-grid analytics-panel-grid-3">
            <div class="analytics-card">
                <div class="analytics-card-header">
                    <div>
                        <h2 class="analytics-card-title">Event Context</h2>
                        <p class="analytics-card-kicker">PRD Epic 6 evidence fields</p>
                    </div>
                </div>
                <div class="analytics-card-body">
                    <div class="analytics-context-list">
                        @foreach([
                            ['label' => 'Organizer', 'value' => $eventData['organizer']['acronym'] ?: $eventData['organizer']['name'], 'accent' => '#0e5d36'],
                            ['label' => 'Proposal / PPA State', 'value' => $label($eventData['status']).' / '.($eventData['proposal_state']['hardcopy_submitted'] ? 'Hardcopy submitted' : 'Hardcopy pending'), 'accent' => '#d97706'],
                            ['label' => 'Schedule', 'value' => $eventData['start_date'].' to '.$eventData['end_date'], 'accent' => '#2563eb'],
                            ['label' => 'Venue', 'value' => $eventData['location_details'] ?: $label($eventData['location_type']), 'accent' => '#4f46e5'],
                            ['label' => 'Who was expected', 'value' => $eventData['audience']['source_label'].' ('.$attendance['expected_audience'].' people)', 'accent' => '#0f766e'],
                        ] as $item)
                            <div class="analytics-context-row">
                                <span class="analytics-context-icon" style="--accent: {{ $item['accent'] }};" aria-hidden="true">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M5 12h14M12 5v14"/></svg>
                                </span>
                                <div>
                                    <p class="analytics-context-label">{{ $item['label'] }}</p>
                                    <p class="analytics-context-value">{{ $item['value'] }}</p>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
            <div class="analytics-card">
                <div class="analytics-card-header">
                    <h2 class="analytics-card-title">Operational Flags</h2>
                </div>
                <div class="analytics-card-body space-y-3">
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-sm text-(--color-text-secondary)">Pending QR links</span>
                        <span class="analytics-chip {{ $scannerSync['pending_links']['pending'] > 0 ? 'analytics-tone-warn' : 'analytics-tone-good' }}"><span class="analytics-chip-dot"></span>{{ $scannerSync['pending_links']['pending'] }}</span>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-sm text-(--color-text-secondary)">Active scanner sessions</span>
                        <span class="analytics-chip {{ $scannerSync['sessions']['active'] > 0 ? 'analytics-tone-good' : 'analytics-tone-neutral' }}"><span class="analytics-chip-dot"></span>{{ $scannerSync['sessions']['active'] }}</span>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-sm text-(--color-text-secondary)">Raw scans persisted</span>
                        <span class="font-semibold font-data text-(--color-text-primary)">{{ $scannerSync['raw_scans']['total'] }}</span>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-sm text-(--color-text-secondary)">Last scanner seen</span>
                        <span class="font-semibold font-data text-(--color-text-primary)">{{ $scannerSync['sessions']['last_seen_at'] ?: 'None' }}</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="analytics-panel-grid analytics-panel-grid-2">
            <div class="analytics-card">
                <div class="analytics-card-header">
                    <div>
                        <h2 class="analytics-card-title">Attendee Demographics</h2>
                        <p class="analytics-card-kicker">{{ $attendance['global_unique_attended'] }} attended students grouped by program</p>
                    </div>
                </div>
                <div class="analytics-card-body">
                    <div id="demographicsChart" class="analytics-chart" aria-label="Attendee program demographics chart"></div>
                    @if($demographics->isEmpty())
                        <p class="analytics-empty">No attendee demographics yet.</p>
                    @endif
                </div>
            </div>

            <div class="analytics-card">
                <div class="analytics-card-header">
                    <div>
                        <h2 class="analytics-card-title">Year Level Mix</h2>
                        <p class="analytics-card-kicker">{{ $attendance['global_unique_attended'] }} attended students grouped by year level</p>
                    </div>
                </div>
                <div class="analytics-card-body">
                    <div id="yearLevelChart" class="analytics-chart" aria-label="Attendee year level chart"></div>
                    @if($yearLevels->isEmpty())
                        <p class="analytics-empty">No year level demographics yet.</p>
                    @endif
                </div>
            </div>
        </div>
    </section>

    <section x-show="activeTab === 'attendance'" x-cloak class="space-y-5">
        <div class="analytics-card">
            <div class="analytics-card-header">
                <div>
                    <h2 class="analytics-card-title">Daily Attendance Trend</h2>
                    <p class="analytics-card-kicker">{{ $attendance['attended'] }} of {{ $attendance['expected_audience'] }} expected</p>
                </div>
            </div>
            <div class="analytics-card-body">
                <div id="attendanceTrendChart" class="analytics-chart" aria-label="Daily attendance trend chart"></div>
            </div>
        </div>

        <div class="analytics-card">
            <div class="analytics-card-header">
                <h2 class="analytics-card-title">Daily EILO Attendance</h2>
                <span class="analytics-card-kicker">{{ $attendance['attended'] }} of {{ $attendance['expected_audience'] }} expected</span>
            </div>
            <div class="analytics-table-wrap">
                <table class="analytics-table">
                    <thead>
                        <tr>
                            <th>Day</th>
                            <th>Attendance</th>
                            <th>Valid</th>
                            <th class="text-right">Time In / Out</th>
                            <th>Status Mix</th>
                            <th class="text-right">Left Early</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($dailyAttendance as $day)
                            <tr>
                                <td>
                                    <p class="font-semibold text-(--color-text-primary)">{{ $day['label'] }}</p>
                                    <p class="text-xs text-(--color-text-tertiary)">{{ $day['date'] }} - {{ $day['start_time'] }} to {{ $day['end_time'] }}</p>
                                </td>
                                <td>
                                    <div class="analytics-mini-bar">
                                        <div class="analytics-mini-bar-top"><span>{{ $day['attended'] }} / {{ $day['expected_audience'] }}</span><span class="font-data">{{ $day['attendance_rate'] }}%</span></div>
                                        <div class="analytics-progress"><div class="analytics-progress-fill" style="--accent: #0e5d36; --bar-width: {{ $rate($day['attendance_rate']) }}%;"></div></div>
                                    </div>
                                </td>
                                <td>
                                    <div class="analytics-mini-bar">
                                        <div class="analytics-mini-bar-top"><span>{{ $day['valid_unique_attended'] }} / {{ $day['expected_audience'] }}</span><span class="font-data">{{ $day['valid_attendance_rate'] }}%</span></div>
                                        <div class="analytics-progress"><div class="analytics-progress-fill" style="--accent: #2563eb; --bar-width: {{ $rate($day['valid_attendance_rate']) }}%;"></div></div>
                                    </div>
                                </td>
                                <td class="text-right font-data">{{ $day['time_in_records'] }} / {{ $day['time_out_records'] }}</td>
                                <td>
                                    <div class="flex flex-wrap gap-1.5">
                                        <span class="analytics-chip analytics-tone-good"><span class="analytics-chip-dot"></span>{{ $day['status_counts']['present_on_time'] }} on time</span>
                                        <span class="analytics-chip analytics-tone-warn"><span class="analytics-chip-dot"></span>{{ $day['status_counts']['late'] }} late</span>
                                        <span class="analytics-chip analytics-tone-danger"><span class="analytics-chip-dot"></span>{{ $day['status_counts']['absent'] }} absent</span>
                                    </div>
                                </td>
                                <td class="text-right font-data">{{ $day['left_early_records'] }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="analytics-empty">No event days are configured.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        @livewire('dashboard.event-analytics-participant-filters', [
            'eventId' => $event->id,
            'filters' => $filters,
            'scopeOptions' => $scopeRollups->map(fn ($scope) => [
                'id' => (string) $scope['id'],
                'name' => (string) $scope['name'],
            ])->values()->all(),
            'dayOptions' => $dailyAttendance->map(fn ($day) => [
                'id' => (string) $day['id'],
                'label' => (string) $day['label'],
                'date' => (string) $day['date'],
            ])->values()->all(),
        ], key('event-analytics-participant-filters-'.$event->id))
    </section>

    <section x-show="activeTab === 'evaluations'" x-cloak class="space-y-5">
        <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
            <div class="analytics-stat-card" style="--accent: #0e5d36;"><div class="analytics-stat-body"><p class="analytics-stat-label">Submitted</p><p class="analytics-stat-number">{{ $evaluations['unique_evaluators'] }}</p><p class="analytics-stat-support">{{ $evaluations['unique_evaluators'] }} / {{ $attendance['global_unique_attended'] }} attended</p></div></div>
            <div class="analytics-stat-card" style="--accent: #d97706;"><div class="analytics-stat-body"><p class="analytics-stat-label">Missing From Attended</p><p class="analytics-stat-number">{{ $evaluations['missing_from_attended'] }}</p><p class="analytics-stat-support">{{ $evaluations['response_rate_attended'] }}% response rate</p></div></div>
            <div class="analytics-stat-card" style="--accent: #2563eb;"><div class="analytics-stat-body"><p class="analytics-stat-label">Average Rating</p><p class="analytics-stat-number">{{ number_format($evaluations['average_rating'], 2) }}</p><p class="analytics-stat-support">Across all category scores</p></div></div>
        </div>

        <div class="analytics-panel-grid analytics-panel-grid-2">
            <div class="analytics-card">
                <div class="analytics-card-header"><h2 class="analytics-card-title">Response Rate</h2></div>
                <div class="analytics-card-body"><div id="evaluationRateChart" class="analytics-chart analytics-chart-short" aria-label="Evaluation response rate gauge"></div></div>
            </div>
            <div class="analytics-card">
                <div class="analytics-card-header"><h2 class="analytics-card-title">Category Averages</h2></div>
                <div class="analytics-card-body"><div id="categoryAverageChart" class="analytics-chart analytics-chart-short" aria-label="Evaluation category averages chart"></div></div>
            </div>
        </div>

        <div class="analytics-card">
            <div class="analytics-card-header">
                <h2 class="analytics-card-title">Score Distribution</h2>
                <span class="analytics-card-kicker">Likert scale 1-5 by category</span>
            </div>
            <div class="analytics-card-body">
                <div id="scoreDistributionChart" class="analytics-chart" aria-label="Evaluation score distribution chart"></div>
            </div>
            <div class="analytics-table-wrap">
                <table class="analytics-table">
                    <thead>
                        <tr>
                            <th>Category</th>
                            <th class="text-right">Average</th>
                            <th class="text-right">Responses</th>
                            @foreach(range(1, 5) as $score)
                                <th class="text-right">{{ $score }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($evaluations['category_averages'] as $category)
                            <tr>
                                <td><p class="font-semibold text-(--color-text-primary)">{{ $category['label'] }}</p><p class="text-xs text-(--color-text-tertiary)">{{ $category['description'] }}</p></td>
                                <td class="text-right font-data">{{ number_format($category['average'], 2) }}</td>
                                <td class="text-right font-data">{{ $category['responses'] }}</td>
                                @foreach(range(1, 5) as $score)
                                    <td class="text-right font-data">{{ $category['distribution'][$score] ?? 0 }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <section x-show="activeTab === 'sentiment'" x-cloak class="space-y-5">
        <div class="analytics-panel-grid analytics-panel-grid-2">
            <div class="analytics-card">
                <div class="analytics-card-header">
                    <h2 class="analytics-card-title">Sentiment Distribution</h2>
                    <span class="analytics-card-kicker">{{ $evaluations['total_submitted'] }} submitted</span>
                </div>
                <div class="analytics-card-body">
                    <div id="sentimentChart" class="analytics-chart" aria-label="Sentiment distribution chart"></div>
                </div>
            </div>
            <div class="analytics-card">
                <div class="analytics-card-header">
                    <h2 class="analytics-card-title">Sentiment Health Score</h2>
                    <span class="analytics-card-kicker">Confidence avg {{ $evaluations['sentiment_confidence']['average'] }}</span>
                </div>
                <div class="analytics-card-body">
                    <div id="sentimentHealthChart" class="analytics-chart" aria-label="Sentiment health score gauge"></div>
                    <div class="grid grid-cols-2 gap-2">
                        @foreach(['positive' => 'Positive', 'neutral' => 'Neutral', 'negative' => 'Negative', 'unprocessed' => 'Unprocessed'] as $key => $text)
                            <div class="rounded-lg border border-(--color-border) bg-(--color-surface-raised) p-3">
                                <p class="text-xs font-bold uppercase text-(--color-text-tertiary)">{{ $text }}</p>
                                <p class="mt-1 font-data text-xl font-bold text-(--color-text-primary)">{{ $evaluations['sentiments'][$key] }}</p>
                                <p class="text-xs text-(--color-text-secondary)">{{ $evaluations['total_submitted'] ? round(($evaluations['sentiments'][$key] / $evaluations['total_submitted']) * 100, 2) : 0 }}%</p>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

        <div class="analytics-card">
            <div class="analytics-card-header">
                <h2 class="analytics-card-title">Comment Review Queue</h2>
                <span class="analytics-card-kicker">Negative and unprocessed comments surface first</span>
            </div>
            <div class="analytics-table-wrap">
                <table class="analytics-table">
                    <thead>
                        <tr>
                            <th>Comment</th>
                            <th>Student</th>
                            <th>Sentiment</th>
                            <th class="text-right">Confidence</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($comments as $comment)
                            <tr>
                                <td class="max-w-xl text-(--color-text-primary)">{{ $comment['comment'] }}</td>
                                <td class="text-xs text-(--color-text-secondary)">{{ $comment['student_name'] ?: 'Unknown' }}<br>{{ $comment['program_code'] ?: 'N/A' }}</td>
                                <td><span class="analytics-chip {{ $toneClass($comment['sentiment']) }}"><span class="analytics-chip-dot"></span>{{ $label($comment['sentiment']) }}</span></td>
                                <td class="text-right font-data">{{ $comment['confidence'] !== null ? number_format($comment['confidence'], 3) : 'N/A' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="analytics-empty">No written comments yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <section x-show="activeTab === 'scopes'" x-cloak class="analytics-card">
        <div class="analytics-card-header">
            <h2 class="analytics-card-title">Participant Scope Rollups</h2>
            <span class="analytics-card-kicker">{{ $eventData['audience']['source_label'] }}</span>
        </div>
        <div class="analytics-table-wrap">
            <table class="analytics-table">
                <thead>
                    <tr>
                        <th>Scope</th>
                        <th>PPA / Capture</th>
                        <th class="text-right">Audience</th>
                        <th>Attendance</th>
                        <th>Evaluations</th>
                        <th class="text-right">Scanner Evidence</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($scopeRollups as $scope)
                        <tr>
                            <td><p class="font-semibold text-(--color-text-primary)">{{ $scope['name'] }}</p><p class="text-xs text-(--color-text-tertiary)">{{ $label($scope['participation_role']) }} - {{ $label($scope['participant_type']) }}</p></td>
                            <td>
                                <div class="flex flex-wrap gap-1.5">
                                    <span class="analytics-chip {{ $toneClass($scope['ppa_status']) }}"><span class="analytics-chip-dot"></span>{{ $label($scope['ppa_status']) }}</span>
                                    <span class="analytics-chip analytics-tone-neutral"><span class="analytics-chip-dot"></span>{{ $label($scope['capture_mode']) }}</span>
                                </div>
                            </td>
                            <td class="text-right font-data">{{ $scope['expected_audience'] }}</td>
                            <td>
                                <div class="analytics-mini-bar">
                                    <div class="analytics-mini-bar-top"><span>{{ $scope['attended'] }} / {{ $scope['expected_audience'] }}</span><span class="font-data">{{ $scope['attendance_rate'] }}%</span></div>
                                    <div class="analytics-progress"><div class="analytics-progress-fill" style="--accent: #0e5d36; --bar-width: {{ $rate($scope['attendance_rate']) }}%;"></div></div>
                                </div>
                            </td>
                            <td>
                                <div class="analytics-mini-bar">
                                    <div class="analytics-mini-bar-top"><span>{{ $scope['evaluation_submissions'] }} / {{ $scope['expected_audience'] }}</span><span class="font-data">{{ $scope['evaluation_rate'] }}%</span></div>
                                    <div class="analytics-progress"><div class="analytics-progress-fill" style="--accent: #d97706; --bar-width: {{ $rate($scope['evaluation_rate']) }}%;"></div></div>
                                </div>
                            </td>
                            <td class="text-right text-xs font-data">{{ $scope['scanner_assignments'] }} assignments - {{ $scope['scanner_sessions'] }} sessions - {{ $scope['raw_scans'] }} scans</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="analytics-empty">No active analytics-enabled scopes.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section x-show="activeTab === 'scanner'" x-cloak class="space-y-5">
        <div class="grid grid-cols-1 gap-3 md:grid-cols-4">
            <div class="analytics-stat-card" style="--accent: #0e5d36;"><div class="analytics-stat-body"><p class="analytics-stat-label">Assignments</p><p class="analytics-stat-number">{{ $scannerSync['assignments']['total'] }}</p><p class="analytics-stat-support">{{ $scannerSync['assignments']['active'] }} active / {{ $scannerSync['assignments']['revoked'] }} revoked</p></div></div>
            <div class="analytics-stat-card" style="--accent: #2563eb;"><div class="analytics-stat-body"><p class="analytics-stat-label">Sessions</p><p class="analytics-stat-number">{{ $scannerSync['sessions']['total'] }}</p><p class="analytics-stat-support">{{ $scannerSync['sessions']['active'] }} active / {{ $scannerSync['sessions']['expired'] }} expired</p></div></div>
            <div class="analytics-stat-card" style="--accent: #d97706;"><div class="analytics-stat-body"><p class="analytics-stat-label">Raw Scans</p><p class="analytics-stat-number">{{ $scannerSync['raw_scans']['total'] }}</p><p class="analytics-stat-support">{{ $scannerSync['raw_scans']['manual_entries'] }} manual / {{ $scannerSync['raw_scans']['unresolved'] }} unresolved</p></div></div>
            <div class="analytics-stat-card" style="--accent: #dc2626;"><div class="analytics-stat-body"><p class="analytics-stat-label">Pending QR</p><p class="analytics-stat-number">{{ $scannerSync['pending_links']['pending'] }}</p><p class="analytics-stat-support">{{ $scannerSync['pending_links']['resolved'] }} resolved / {{ $scannerSync['pending_links']['flagged'] }} flagged</p></div></div>
        </div>

        <div class="analytics-panel-grid analytics-panel-grid-2">
            <div class="analytics-card">
                <div class="analytics-card-header"><h2 class="analytics-card-title">Scanner Assignments</h2></div>
                <div class="analytics-table-wrap">
                    <table class="analytics-table">
                        <thead><tr><th>Scanner</th><th>Scope</th><th>Status</th><th class="text-right">Last Active</th></tr></thead>
                        <tbody>
                            @forelse($scannerSync['assignments']['rows'] as $assignment)
                                <tr><td><p class="font-semibold text-(--color-text-primary)">{{ $assignment['label'] ?: 'Scanner assignment' }}</p><p class="text-xs text-(--color-text-tertiary)">{{ $assignment['scanner_name'] ?: 'Unassigned' }}</p></td><td class="text-xs text-(--color-text-secondary)">{{ $assignment['scope_name'] ?: 'Event' }}</td><td><span class="analytics-chip {{ $toneClass($assignment['status']) }}"><span class="analytics-chip-dot"></span>{{ $label($assignment['status']) }}</span></td><td class="text-right text-xs font-data">{{ $assignment['last_activated_at'] ?: 'None' }}</td></tr>
                            @empty
                                <tr><td colspan="4" class="analytics-empty">No scanner assignments.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="analytics-card">
                <div class="analytics-card-header"><h2 class="analytics-card-title">Session Activity</h2></div>
                <div class="analytics-table-wrap">
                    <table class="analytics-table">
                        <thead><tr><th>Session</th><th>Device</th><th>Status</th><th class="text-right">Last Seen</th></tr></thead>
                        <tbody>
                            @forelse($scannerSync['sessions']['rows'] as $session)
                                <tr><td><p class="font-semibold text-(--color-text-primary)">{{ $session['label'] ?: 'Scanner session' }}</p><p class="text-xs text-(--color-text-tertiary)">{{ $session['scanner_name'] ?: 'Unknown scanner' }}</p></td><td class="text-xs text-(--color-text-secondary)">{{ $session['device_id'] ?: 'No device' }}</td><td><span class="analytics-chip {{ $toneClass($session['status']) }}"><span class="analytics-chip-dot"></span>{{ $label($session['status']) }}</span></td><td class="text-right text-xs font-data">{{ $session['last_seen_at'] ?: 'None' }}</td></tr>
                            @empty
                                <tr><td colspan="4" class="analytics-empty">No scanner sessions.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </section>

    <section x-show="activeTab === 'exports'" x-cloak class="analytics-card">
        <div class="analytics-card-header">
            <div>
                <h2 class="analytics-card-title">Verified Exports</h2>
                <p class="analytics-card-kicker">Timestamped PDF and Excel outputs</p>
            </div>
        </div>
        <div class="analytics-card-body">
            <div class="analytics-export-grid">
                @foreach([
                    ['title' => 'Attendance PDF', 'type' => 'PDF', 'accent' => '#dc2626', 'url' => route('dashboard.events.exports.attendance.pdf', $event), 'copy' => 'Validated attendance summary, including who was expected to attend.'],
                    ['title' => 'Attendance XLSX', 'type' => 'XLSX', 'accent' => '#0e5d36', 'url' => route('dashboard.events.exports.attendance.excel', $event), 'copy' => 'Spreadsheet attendance records and EILO timestamps.'],
                    ['title' => 'Evaluations PDF', 'type' => 'PDF', 'accent' => '#d97706', 'url' => route('dashboard.events.exports.evaluations.pdf', $event), 'copy' => 'Evaluation response summary and submitted feedback evidence.'],
                    ['title' => 'Evaluations XLSX', 'type' => 'XLSX', 'accent' => '#2563eb', 'url' => route('dashboard.events.exports.evaluations.excel', $event), 'copy' => 'Raw evaluation scores, comments, and sentiment fields.'],
                ] as $export)
                    <a href="{{ $export['url'] }}" class="analytics-export-card">
                        <span class="analytics-export-icon" style="--accent: {{ $export['accent'] }};" aria-hidden="true">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5l5 5v11a2 2 0 01-2 2z"/></svg>
                        </span>
                        <span>
                            <span class="analytics-chip analytics-tone-neutral"><span class="analytics-chip-dot"></span>{{ $export['type'] }}</span>
                            <span class="mt-2 block font-semibold text-(--color-text-primary)">{{ $export['title'] }}</span>
                            <span class="mt-1 block text-xs text-(--color-text-secondary)">{{ $export['copy'] }}</span>
                        </span>
                    </a>
                @endforeach
            </div>
        </div>
    </section>
</div>
</x-layouts.app>
