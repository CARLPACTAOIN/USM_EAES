<x-layouts.app :title="'PPA Proposal Preview - '.$event->title">
    <div class="dashboard-page-header">
        <div>
            <p class="text-sm font-semibold uppercase text-(--color-text-tertiary)">Generated from Canonical PPA Packet</p>
            <h1 class="dashboard-page-title">Program / Project / Activity Proposal</h1>
            <p class="dashboard-page-subtitle">{{ $event->organization?->name ?? 'Organizer unavailable' }}</p>
        </div>
        <a href="{{ route('dashboard.events') }}" class="btn btn-secondary">Back to Events</a>
    </div>

    <div class="grid gap-4">
        <section class="card p-5">
            <h2 class="text-lg font-semibold">A. Basic Information</h2>
            <div class="proposal-detail-grid mt-4">
                <div class="proposal-detail-item"><span class="proposal-detail-label">Category</span><span class="proposal-detail-value">{{ ucfirst((string) $packet->proposal_category) }}</span></div>
                <div class="proposal-detail-item"><span class="proposal-detail-label">Activity role</span><span class="proposal-detail-value">{{ ucwords(str_replace('-', ' ', (string) $packet->activity_role)) }}</span></div>
                <div class="proposal-detail-item proposal-detail-wide"><span class="proposal-detail-label">Title</span><span class="proposal-detail-value">{{ $packet->title }}</span></div>
                <div class="proposal-detail-item"><span class="proposal-detail-label">Proponent</span><span class="proposal-detail-value">{{ $packet->proponent_name }}</span></div>
                <div class="proposal-detail-item"><span class="proposal-detail-label">Position</span><span class="proposal-detail-value">{{ $packet->proponent_position ?: 'N/A' }}</span></div>
                <div class="proposal-detail-item"><span class="proposal-detail-label">Organization</span><span class="proposal-detail-value">{{ $packet->proponent_organization }}</span></div>
                <div class="proposal-detail-item"><span class="proposal-detail-label">Email</span><span class="proposal-detail-value">{{ $packet->proponent_email }}</span></div>
                <div class="proposal-detail-item"><span class="proposal-detail-label">Contact number</span><span class="proposal-detail-value">{{ $packet->proponent_contact_number ?: 'N/A' }}</span></div>
                <div class="proposal-detail-item"><span class="proposal-detail-label">Implementing office</span><span class="proposal-detail-value">{{ $packet->implementing_office }}</span></div>
                <div class="proposal-detail-item"><span class="proposal-detail-label">Collaborating office</span><span class="proposal-detail-value">{{ $packet->collaborating_office ?: 'N/A' }}</span></div>
                <div class="proposal-detail-item"><span class="proposal-detail-label">Source of fund</span><span class="proposal-detail-value">{{ $packet->source_of_fund }}</span></div>
                <div class="proposal-detail-item"><span class="proposal-detail-label">Budget / cost</span><span class="proposal-detail-value">PHP {{ number_format((float) $packet->budget_cost, 2) }}</span></div>
                <div class="proposal-detail-item"><span class="proposal-detail-label">Duration</span><span class="proposal-detail-value">{{ $packet->start_date?->toFormattedDateString() }} - {{ $packet->end_date?->toFormattedDateString() }}</span></div>
                <div class="proposal-detail-item"><span class="proposal-detail-label">Location</span><span class="proposal-detail-value">{{ ucwords(str_replace('-', ' ', (string) $packet->location_type)) }}: {{ $packet->location_details }}</span></div>
            </div>
        </section>

        <section class="card p-5">
            <h2 class="text-lg font-semibold">B. Introduction</h2>
            <div class="grid gap-4 mt-4">
                <div><p class="form-label">Rationale / Significance</p><p>{{ $packet->rationale }}</p></div>
                <div><p class="form-label">General Objective</p><p>{{ $packet->general_objective }}</p></div>
                <div>
                    <p class="form-label">Specific Objectives</p>
                    <ul class="list-disc pl-5">
                        @foreach($packet->specific_objectives ?? [] as $objective)
                            <li>{{ $objective }}</li>
                        @endforeach
                    </ul>
                </div>
                <div><p class="form-label">Methodology / Mechanics and Guidelines</p><p>{{ $packet->methodology }}</p></div>
                <div>
                    <p class="form-label">Major Activities / Program of Activities</p>
                    @if(($packet->program_activities ?? []) !== [])
                        <div class="table-responsive mt-2">
                            <table class="data-table">
                                <thead><tr><th>Date</th><th>Time</th><th>Activity</th><th>Person-in-Charge</th></tr></thead>
                                <tbody>
                                    @foreach($packet->program_activities as $activity)
                                        <tr>
                                            <td>{{ $activity['date'] ?? 'N/A' }}</td>
                                            <td>{{ $activity['time'] ?? 'N/A' }}</td>
                                            <td>{{ $activity['activity'] ?? 'N/A' }}</td>
                                            <td>{{ $activity['person_in_charge'] ?? 'N/A' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @else
                        <ul class="list-disc pl-5">
                            @foreach($packet->major_activities ?? [] as $activity)
                                <li>{{ $activity }}</li>
                            @endforeach
                        </ul>
                    @endif
                </div>
                <div><p class="form-label">Expected Output</p><p>{{ $packet->expected_output }}</p></div>
                <div><p class="form-label">Target Beneficiaries / Participants</p><p>{{ $packet->beneficiaries }}</p></div>
            </div>
        </section>

        <section class="card p-5">
            <h2 class="text-lg font-semibold">Budget Breakdown</h2>
            <div class="table-responsive mt-4">
                <table class="data-table">
                    <thead><tr><th>Expense Item</th><th>Amount</th></tr></thead>
                    <tbody>
                        @foreach($packet->budget_breakdown ?? [] as $item)
                            <tr>
                                <td>{{ $item['item'] ?? 'N/A' }}</td>
                                <td>PHP {{ number_format((float) ($item['amount'] ?? 0), 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        <section class="card p-5">
            <h2 class="text-lg font-semibold">Approval / Signatories</h2>
            <div class="proposal-detail-grid mt-4">
                <div class="proposal-detail-item">
                    <span class="proposal-detail-label">Prepared by</span>
                    <span class="proposal-detail-value">{{ $packet->proponent_name }}</span>
                </div>
                <div class="proposal-detail-item">
                    <span class="proposal-detail-label">Approval</span>
                    <span class="proposal-detail-value">Head of Organization</span>
                </div>
                <div class="proposal-detail-item">
                    <span class="proposal-detail-label">Noted by</span>
                    <span class="proposal-detail-value">Adviser</span>
                </div>
            </div>
        </section>
    </div>
</x-layouts.app>
