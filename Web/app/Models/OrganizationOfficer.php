<?php

namespace App\Models;

use Database\Factories\OrganizationOfficerFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationOfficer extends Model
{
    /** @use HasFactory<OrganizationOfficerFactory> */
    use HasFactory, HasUuids;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_REPLACED = 'replaced';

    public const STATUS_REVOKED = 'revoked';

    public const STATUS_EXPIRED = 'expired';

    public const POSITION_PRESIDENT = 'president';

    public const POSITION_SECRETARY = 'secretary';

    public const POSITION_ADVISER = 'adviser';

    protected $fillable = [
        'officer_roster_id',
        'user_id',
        'position_key',
        'position_title',
        'status',
        'effective_start',
        'effective_end',
        'replacement_officer_id',
        'metadata',
    ];

    protected $attributes = [
        'status' => self::STATUS_ACTIVE,
    ];

    protected function casts(): array
    {
        return [
            'effective_start' => 'date',
            'effective_end' => 'date',
            'metadata' => 'array',
        ];
    }

    public function roster(): BelongsTo
    {
        return $this->belongsTo(OrganizationOfficerRoster::class, 'officer_roster_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function replacementOfficer(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replacement_officer_id');
    }

    public function isPresident(): bool
    {
        return $this->position_key === self::POSITION_PRESIDENT;
    }

    public function isSecretary(): bool
    {
        return $this->position_key === self::POSITION_SECRETARY;
    }

    public function isAdviser(): bool
    {
        return $this->position_key === self::POSITION_ADVISER;
    }
}
