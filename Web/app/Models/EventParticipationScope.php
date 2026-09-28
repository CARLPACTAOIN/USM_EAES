<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class EventParticipationScope extends Model
{
    use HasFactory, HasUuids;

    public const ROLE_ORGANIZER = 'organizer';

    public const ROLE_PARTICIPANT = 'participant';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_REVOKED = 'revoked';

    public const STATUS_INACTIVE = 'inactive';

    public const PPA_DRAFT = 'draft';

    public const PPA_SUBMITTED = 'submitted';

    public const PPA_UNDER_REVIEW = 'under_review';

    public const PPA_REVISION_REQUESTED = 'revision_requested';

    public const PPA_APPROVED = 'approved';

    public const PPA_REJECTED = 'rejected';

    public const PPA_WITHDRAWN = 'withdrawn';

    public const PPA_NOT_REQUIRED = 'not_required';

    protected $fillable = [
        'event_id',
        'school_year_id',
        'parent_scope_id',
        'linked_event_id',
        'participation_role',
        'participant_type',
        'organization_id',
        'college_id',
        'department_id',
        'program_id',
        'participant_name_snapshot',
        'ppa_required',
        'ppa_status',
        'ppa_softcopy_path',
        'ppa_softcopy_original_name',
        'hardcopy_submitted',
        'org_head_signed',
        'adviser_signed',
        'submitted_by',
        'submitted_at',
        'reviewed_by',
        'reviewed_at',
        'review_remarks',
        'approved_at',
        'capture_mode',
        'delegated_to_organization_id',
        'analytics_rollup_enabled',
        'late_cutoff_min',
        'status',
        'invitation_acknowledged_at',
        'invitation_acknowledged_by',
        'invitation_last_reminded_at',
    ];

    protected function casts(): array
    {
        return [
            'ppa_required' => 'boolean',
            'hardcopy_submitted' => 'boolean',
            'org_head_signed' => 'boolean',
            'adviser_signed' => 'boolean',
            'analytics_rollup_enabled' => 'boolean',
            'late_cutoff_min' => 'integer',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'approved_at' => 'datetime',
            'invitation_acknowledged_at' => 'datetime',
            'invitation_last_reminded_at' => 'datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function canonicalPpaPacket(): HasOne
    {
        return $this->hasOne(CanonicalPpaPacket::class, 'event_participation_scope_id');
    }

    public function schoolYear(): BelongsTo
    {
        return $this->belongsTo(SchoolYear::class);
    }

    public function parentScope(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_scope_id');
    }

    public function childScopes(): HasMany
    {
        return $this->hasMany(self::class, 'parent_scope_id');
    }

    public function linkedEvent(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'linked_event_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function college(): BelongsTo
    {
        return $this->belongsTo(College::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function delegatedOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'delegated_to_organization_id');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function invitationAcknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invitation_acknowledged_by');
    }

    public function scannerAssignments(): HasMany
    {
        return $this->hasMany(EventScannerAssignment::class, 'event_participation_scope_id');
    }

    public function scannerSessions(): HasMany
    {
        return $this->hasMany(ScannerSession::class, 'event_participation_scope_id');
    }

    public function workflowRun(): HasOne
    {
        return $this->hasOne(ProposalWorkflowRun::class, 'event_participation_scope_id');
    }

    public function rawScans(): HasMany
    {
        return $this->hasMany(RawScan::class, 'event_participation_scope_id');
    }

    public function attendanceRecords(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class, 'event_participation_scope_id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeApprovedForRuntime($query)
    {
        return $query->active()
            ->where(function ($query): void {
                $query->where('ppa_required', false)
                    ->orWhereIn('ppa_status', [self::PPA_APPROVED, self::PPA_NOT_REQUIRED]);
            });
    }

    public function isApprovedForRuntime(): bool
    {
        if ($this->status !== self::STATUS_ACTIVE) {
            return false;
        }

        return ! $this->ppa_required || in_array($this->ppa_status, [self::PPA_APPROVED, self::PPA_NOT_REQUIRED], true);
    }

    public function isOrganizer(): bool
    {
        return $this->participation_role === self::ROLE_ORGANIZER;
    }

    public function isParticipant(): bool
    {
        return $this->participation_role === self::ROLE_PARTICIPANT;
    }

    public function displayName(): string
    {
        return $this->participant_name_snapshot
            ?: $this->organization?->acronym
            ?: $this->department?->code
            ?: $this->college?->code
            ?: $this->program?->code
            ?: 'Participant scope';
    }
}
