<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Digital Permit Certificate</title>
    <link rel="stylesheet" href="{{ asset('css/permit-certificate.css') }}">
</head>
<body>
    <header class="header">
        <p class="eyebrow">University of Southern Mindanao</p>
        <h1 class="title">Digital Student Activity Permit</h1>
        <p>{{ data_get($permitVersion->proposal_snapshot, 'organization_name_snapshot') ?: data_get($permitVersion->proposal_snapshot, 'organization_name') ?: $event->organization_name_snapshot ?: $event->organization?->name ?: 'Organizing office' }}</p>
        <div class="reference">
            <p class="eyebrow">Verification Reference</p>
            <h2>{{ $permitVersion->verification_reference }}</h2>
            <p>Permit version {{ $permitVersion->version_number }} issued {{ $permitVersion->approved_at?->format('F d, Y g:i A') }}</p>
        </div>
    </header>

    <section class="section">
        <h2>Permit Details</h2>
        <div class="grid">
            <div class="row"><div class="label">Activity</div><div class="value">{{ data_get($permitVersion->canonical_packet_snapshot, 'title', $event->title) }}</div></div>
            <div class="row"><div class="label">School Year</div><div class="value">{{ data_get($permitVersion->proposal_snapshot, 'school_year_label') ?: data_get($permitVersion->proposal_snapshot, 'governance_snapshots.metadata.school_year_label') ?: $event->schoolYear?->label ?: 'N/A' }}</div></div>
            <div class="row"><div class="label">College</div><div class="value">{{ data_get($permitVersion->proposal_snapshot, 'college_name_snapshot') ?: data_get($permitVersion->proposal_snapshot, 'governance_snapshots.college_name_snapshot') ?: $event->college_name_snapshot ?: $event->organization?->college?->name ?: 'N/A' }}</div></div>
            <div class="row"><div class="label">Department</div><div class="value">{{ data_get($permitVersion->proposal_snapshot, 'department_name_snapshot') ?: data_get($permitVersion->proposal_snapshot, 'governance_snapshots.department_name_snapshot') ?: $event->department_name_snapshot ?: 'N/A' }}</div></div>
            @if(data_get($permitVersion->proposal_snapshot, 'program_name_snapshot') || data_get($permitVersion->proposal_snapshot, 'governance_snapshots.program_name_snapshot') || $event->program_name_snapshot)
                <div class="row"><div class="label">Program</div><div class="value">{{ data_get($permitVersion->proposal_snapshot, 'program_name_snapshot') ?: data_get($permitVersion->proposal_snapshot, 'governance_snapshots.program_name_snapshot') ?: $event->program_name_snapshot }}</div></div>
            @endif
            <div class="row"><div class="label">Proponent</div><div class="value">{{ data_get($permitVersion->proposal_snapshot, 'proponent_name_snapshot') ?: data_get($permitVersion->proposal_snapshot, 'governance_snapshots.proponent_name_snapshot') ?: $event->proponent_name_snapshot ?: 'N/A' }}</div></div>
            <div class="row"><div class="label">Role</div><div class="value">{{ str(data_get($permitVersion->canonical_packet_snapshot, 'activity_role', 'organizer'))->replace('-', ' ')->title() }}</div></div>
            <div class="row"><div class="label">Inclusive dates</div><div class="value">{{ data_get($permitVersion->canonical_packet_snapshot, 'start_date') }} to {{ data_get($permitVersion->canonical_packet_snapshot, 'end_date') }}</div></div>
            <div class="row"><div class="label">Venue / platform</div><div class="value">{{ $permitVersion->venue_name_snapshot }} @if($permitVersion->venue_location_snapshot) - {{ $permitVersion->venue_location_snapshot }} @endif</div></div>
            <div class="row"><div class="label">Approving office</div><div class="value">Office of Student Affairs</div></div>
            <div class="row"><div class="label">Final approver</div><div class="value">{{ $permitVersion->approver?->name ?? data_get($permitVersion->permit_snapshot, 'approved_by.name') }}</div></div>
        </div>
    </section>

    <section class="section">
        <h2>Approval Metadata</h2>
        @forelse($permitVersion->approval_metadata_snapshot ?? [] as $metadata)
            <div class="metadata-block">
                <strong>{{ $metadata['step_title'] ?? 'Workflow task' }}</strong>
                <p>{{ str($metadata['decision'] ?? 'approved')->replace('_', ' ')->title() }} by {{ $metadata['actor_name'] ?? 'Recorded approver' }}</p>
                <p>Reference: {{ $metadata['reference_id'] ?? 'N/A' }}</p>
                @if(! blank($metadata['remarks'] ?? null))
                    <p>Remarks: {{ $metadata['remarks'] }}</p>
                @endif
            </div>
        @empty
            <p>No workflow approval metadata was captured for this permit version.</p>
        @endforelse
    </section>

    <section class="section">
        <h2>Official Copy Owners</h2>
        <div class="grid">
            @foreach($permitVersion->copyOwners as $owner)
                <div class="row">
                    <div class="label">{{ $owner->copy_label }}</div>
                    <div class="value">{{ $owner->owner_name }} - {{ $owner->owner_role }}</div>
                </div>
            @endforeach
        </div>
    </section>

    <footer class="footer">
        This certificate is generated by EAES from the approved Canonical PPA Packet, immutable Approval Trail metadata, and official permit copy ownership records. Visual signature images are not required.
    </footer>
</body>
</html>
