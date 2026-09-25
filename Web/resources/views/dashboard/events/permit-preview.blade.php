<x-layouts.app :title="'Activity Permit Preview - '.$event->title">
    <div class="dashboard-page-header">
        <div>
            <p class="text-sm font-semibold uppercase text-(--color-text-tertiary)">Generated from Canonical PPA Packet</p>
            <h1 class="dashboard-page-title">Student Activity Permit Preview</h1>
            <p class="dashboard-page-subtitle">{{ $event->organization?->name ?? 'Organizer unavailable' }}</p>
        </div>
        <a href="{{ route('dashboard.events') }}" class="btn btn-secondary">Back to Events</a>
    </div>

    <div class="grid gap-4">
        <section class="card p-5">
            <h2 class="text-lg font-semibold">Activity Details</h2>
            <div class="proposal-detail-grid mt-4">
                <div class="proposal-detail-item"><span class="proposal-detail-label">Activity role</span><span class="proposal-detail-value">{{ ucwords(str_replace('-', ' ', (string) $packet->activity_role)) }}</span></div>
                <div class="proposal-detail-item"><span class="proposal-detail-label">Location branch</span><span class="proposal-detail-value">{{ ucwords(str_replace('-', ' ', (string) $packet->location_type)) }}</span></div>
                <div class="proposal-detail-item proposal-detail-wide"><span class="proposal-detail-label">Activity title</span><span class="proposal-detail-value">{{ $packet->title }}</span></div>
                <div class="proposal-detail-item"><span class="proposal-detail-label">Inclusive dates</span><span class="proposal-detail-value">{{ $packet->start_date?->toFormattedDateString() }} - {{ $packet->end_date?->toFormattedDateString() }}</span></div>
                <div class="proposal-detail-item"><span class="proposal-detail-label">Venue / platform</span><span class="proposal-detail-value">{{ $packet->location_details }}</span></div>
                <div class="proposal-detail-item"><span class="proposal-detail-label">Prepared by</span><span class="proposal-detail-value">{{ $packet->proponent_name }}</span></div>
                <div class="proposal-detail-item"><span class="proposal-detail-label">Contact</span><span class="proposal-detail-value">{{ $packet->proponent_email }} @if($packet->proponent_contact_number) / {{ $packet->proponent_contact_number }} @endif</span></div>
                <div class="proposal-detail-item"><span class="proposal-detail-label">Implementing office</span><span class="proposal-detail-value">{{ $packet->implementing_office }}</span></div>
            </div>
        </section>

        <section class="card p-5">
            <h2 class="text-lg font-semibold">Permit Branch</h2>
            <div class="proposal-detail-grid mt-4">
                @if($packet->location_type === 'on-campus')
                    <div class="proposal-detail-item"><span class="proposal-detail-label">Campus venue mode</span><span class="proposal-detail-value">{{ $packet->venue_id ? 'OSA venue catalog' : 'Manual campus venue' }}</span></div>
                    <div class="proposal-detail-item"><span class="proposal-detail-label">Venue record</span><span class="proposal-detail-value">{{ $event->venue?->displayName() ?? $packet->location_details }}</span></div>
                @elseif($packet->location_type === 'off-campus')
                    <div class="proposal-detail-item proposal-detail-wide"><span class="proposal-detail-label">Off-campus venue address</span><span class="proposal-detail-value">{{ $packet->location_details }}</span></div>
                    <div class="proposal-detail-item"><span class="proposal-detail-label">Confirming office</span><span class="proposal-detail-value">{{ $packet->venue_confirmation_office }}</span></div>
                    <div class="proposal-detail-item"><span class="proposal-detail-label">Confirmation date</span><span class="proposal-detail-value">{{ $packet->venue_confirmation_date?->toFormattedDateString() }}</span></div>
                    <div class="proposal-detail-item"><span class="proposal-detail-label">Reference number</span><span class="proposal-detail-value">{{ $packet->venue_confirmation_reference_number ?: 'N/A' }}</span></div>
                    <div class="proposal-detail-item"><span class="proposal-detail-label">Private evidence</span><span class="proposal-detail-value">{{ $packet->venue_confirmation_document_original_name ?: 'Not attached' }}</span></div>
                @else
                    <div class="proposal-detail-item proposal-detail-wide"><span class="proposal-detail-label">Online platform or link</span><span class="proposal-detail-value">{{ $packet->location_details }}</span></div>
                    <div class="proposal-detail-item"><span class="proposal-detail-label">Venue confirmation route</span><span class="proposal-detail-value">Not required for online packets</span></div>
                @endif
            </div>
        </section>

        <section class="card p-5">
            <h2 class="text-lg font-semibold">Schedule</h2>
            <div class="table-responsive mt-4">
                <table class="data-table">
                    <thead><tr><th>Date</th><th>Time</th><th>Activity</th><th>Person-in-Charge</th></tr></thead>
                    <tbody>
                        @foreach($packet->program_activities ?? [] as $activity)
                            <tr>
                                <td>{{ $activity['date'] ?? 'N/A' }}</td>
                                <td>{{ $activity['time'] ?? 'N/A' }}</td>
                                <td>{{ $activity['activity'] ?? $packet->title }}</td>
                                <td>{{ $activity['person_in_charge'] ?? $packet->proponent_name }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        <section class="card p-5">
            <h2 class="text-lg font-semibold">Approval / Signatories</h2>
            <div class="proposal-detail-grid mt-4">
                <div class="proposal-detail-item"><span class="proposal-detail-label">Prepared by</span><span class="proposal-detail-value">{{ $packet->proponent_name }}</span></div>
                <div class="proposal-detail-item"><span class="proposal-detail-label">Approval</span><span class="proposal-detail-value">Head of Organization</span></div>
                <div class="proposal-detail-item"><span class="proposal-detail-label">Noted by</span><span class="proposal-detail-value">Adviser</span></div>
                <div class="proposal-detail-item"><span class="proposal-detail-label">Permit version</span><span class="proposal-detail-value">{{ $event->permit_version > 0 ? 'v'.$event->permit_version : 'Pending approval' }}</span></div>
            </div>
        </section>
    </div>
</x-layouts.app>
