<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Event extends Model
{
    use HasFactory, HasUuids;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_UNDER_REVIEW = 'under_review';

    public const STATUS_REVISION_REQUESTED = 'revision_requested';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_COMPLETED = 'completed';

    protected $fillable = [
        'organization_id',
        'parent_event_id',
        'title',
        'proposal_category',
        'status', // Proposal workflow: "draft", "submitted", "under_review", "revision_requested", "approved", "rejected", "completed"
        'lifecycle_phase',
        'event_domain',
        'start_date',
        'end_date',
        'location_type',
        'venue_id',
        'location_details',
        'venue_confirmation_office',
        'venue_confirmation_date',
        'venue_confirmation_reference_number',
        'venue_confirmation_document_path',
        'venue_confirmation_document_original_name',
        'permit_version',
        'pending_venue_amendment_reason',
        'target_demographics',
        'budget_allocations',
        'proposal_document_path',
        'proposal_document_original_name',
        'resolution_number',
        'hardcopy_submitted',
        'hardcopy_submitted_at',
        'head_organization_signed',
        'adviser_signed',
        'society_late_threshold_min',
        'general_competition_threshold_min',
        'event_late_cutoff_min',
        'left_early_buffer_min',
        'evaluation_open',
        'evaluation_form_version_id',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'venue_confirmation_date' => 'date',
        'permit_version' => 'integer',
        'target_demographics' => 'array',
        'budget_allocations' => 'array',
        'hardcopy_submitted' => 'boolean',
        'hardcopy_submitted_at' => 'datetime',
        'head_organization_signed' => 'boolean',
        'adviser_signed' => 'boolean',
        'evaluation_open' => 'boolean',
        'event_late_cutoff_min' => 'integer',
        'society_late_threshold_min' => 'integer',
        'general_competition_threshold_min' => 'integer',
        'left_early_buffer_min' => 'integer',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    public function permitVersions(): HasMany
    {
        return $this->hasMany(EventPermitVersion::class)->orderByDesc('version_number');
    }

    public function currentPermitVersion(): HasOne
    {
        return $this->hasOne(EventPermitVersion::class)
            ->whereNull('superseded_at')
            ->latestOfMany('version_number');
    }

    public function requiresVenueConfirmation(): bool
    {
        return $this->event_domain !== 'hr';
    }

    public function parentEvent()
    {
        return $this->belongsTo(Event::class, 'parent_event_id');
    }

    public function subEvents()
    {
        return $this->hasMany(Event::class, 'parent_event_id');
    }

    public function eventDays()
    {
        return $this->hasMany(EventDay::class);
    }

    public function rawScans()
    {
        return $this->hasMany(RawScan::class);
    }

    public function scannerSessions()
    {
        return $this->hasMany(ScannerSession::class);
    }

    public function scannerAssignments()
    {
        return $this->hasMany(EventScannerAssignment::class);
    }

    public function participationScopes()
    {
        return $this->hasMany(EventParticipationScope::class);
    }

    public function lateCutoffMinutesFor(?EventParticipationScope $scope = null): int
    {
        if ($this->event_late_cutoff_min !== null) {
            return (int) $this->event_late_cutoff_min;
        }

        if ($scope?->late_cutoff_min !== null) {
            return (int) $scope->late_cutoff_min;
        }

        return (int) ($this->society_late_threshold_min ?? 15);
    }

    public function organizerParticipationScope()
    {
        return $this->hasOne(EventParticipationScope::class)
            ->where('participation_role', EventParticipationScope::ROLE_ORGANIZER);
    }

    public function attendanceRecords()
    {
        return $this->hasMany(AttendanceRecord::class);
    }

    public function evaluations()
    {
        return $this->hasMany(Evaluation::class);
    }

    public function evaluationFormVersion()
    {
        return $this->belongsTo(EvaluationFormVersion::class, 'evaluation_form_version_id');
    }

    public function evaluationFormVersions()
    {
        return $this->hasMany(EvaluationFormVersion::class);
    }
}
