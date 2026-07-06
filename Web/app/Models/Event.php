<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Event extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'organization_id',
        'parent_event_id',
        'title',
        'proposal_category',
        'status', // Proposal workflow: "draft", "submitted", "under_review", "approved", "rejected", "completed"
        'lifecycle_phase',
        'event_domain',
        'start_date',
        'end_date',
        'location_type',
        'location_details',
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
        'left_early_buffer_min',
        'evaluation_open',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'target_demographics' => 'array',
        'budget_allocations' => 'array',
        'hardcopy_submitted' => 'boolean',
        'hardcopy_submitted_at' => 'datetime',
        'head_organization_signed' => 'boolean',
        'adviser_signed' => 'boolean',
        'evaluation_open' => 'boolean',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class);
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
}
