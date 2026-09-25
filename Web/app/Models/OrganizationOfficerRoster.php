<?php

namespace App\Models;

use Database\Factories\OrganizationOfficerRosterFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrganizationOfficerRoster extends Model
{
    /** @use HasFactory<OrganizationOfficerRosterFactory> */
    use HasFactory, HasUuids;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_CHANGES_REQUESTED = 'changes_requested';

    public const STATUS_VERIFIED = 'verified';

    public const STATUS_LOCKED = 'locked';

    public const STATUS_SUPERSEDED = 'superseded';

    protected $fillable = [
        'organization_id',
        'school_year_id',
        'status',
        'evidence_file_path',
        'evidence_file_name',
        'attestation_text',
        'evidence_metadata',
        'submitted_by',
        'submitted_at',
        'verified_by',
        'verified_at',
        'verification_remarks',
        'rejection_remarks',
        'created_by',
    ];

    protected $attributes = [
        'status' => self::STATUS_DRAFT,
    ];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'verified_at' => 'datetime',
            'evidence_metadata' => 'array',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function schoolYear(): BelongsTo
    {
        return $this->belongsTo(SchoolYear::class);
    }

    public function officers(): HasMany
    {
        return $this->hasMany(OrganizationOfficer::class, 'officer_roster_id');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function president(): ?OrganizationOfficer
    {
        return $this->officers->firstWhere('position_key', OrganizationOfficer::POSITION_PRESIDENT);
    }

    public function secretary(): ?OrganizationOfficer
    {
        return $this->officers->firstWhere('position_key', OrganizationOfficer::POSITION_SECRETARY);
    }

    public function adviser(): ?OrganizationOfficer
    {
        return $this->officers->firstWhere('position_key', OrganizationOfficer::POSITION_ADVISER);
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isSubmitted(): bool
    {
        return $this->status === self::STATUS_SUBMITTED;
    }

    public function isVerified(): bool
    {
        return $this->status === self::STATUS_VERIFIED;
    }

    public function isEditable(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_CHANGES_REQUESTED], true);
    }
}
