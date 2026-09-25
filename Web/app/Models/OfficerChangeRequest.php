<?php

namespace App\Models;

use Database\Factories\OfficerChangeRequestFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OfficerChangeRequest extends Model
{
    /** @use HasFactory<OfficerChangeRequestFactory> */
    use HasFactory, HasUuids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    public const TYPE_RESIGNATION = 'resignation';

    public const TYPE_REPLACEMENT = 'replacement';

    public const TYPE_REVOCATION = 'revocation';

    public const TYPE_CORRECTION = 'correction';

    protected $fillable = [
        'organization_id',
        'officer_roster_id',
        'old_officer_id',
        'new_user_id',
        'created_officer_id',
        'request_type',
        'position_key',
        'position_title',
        'status',
        'reason',
        'evidence_file_path',
        'evidence_file_name',
        'evidence_metadata',
        'requested_by',
        'requested_at',
        'decided_by',
        'decided_at',
        'decision_remarks',
    ];

    protected $attributes = [
        'status' => self::STATUS_PENDING,
    ];

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'decided_at' => 'datetime',
            'evidence_metadata' => 'array',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function roster(): BelongsTo
    {
        return $this->belongsTo(OrganizationOfficerRoster::class, 'officer_roster_id');
    }

    public function oldOfficer(): BelongsTo
    {
        return $this->belongsTo(OrganizationOfficer::class, 'old_officer_id');
    }

    public function newUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'new_user_id');
    }

    public function createdOfficer(): BelongsTo
    {
        return $this->belongsTo(OrganizationOfficer::class, 'created_officer_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }
}
