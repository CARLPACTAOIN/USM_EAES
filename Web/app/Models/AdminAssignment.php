<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminAssignment extends Model
{
    use HasFactory, HasUuids;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_REVOKED = 'revoked';

    public const SOURCE_VERIFIED_OFFICER = 'verified_officer';

    public const SOURCE_OSA_OVERRIDE = 'osa_override';

    protected $fillable = [
        'user_id',
        'role_name',
        'organization_id',
        'college_id',
        'department_id',
        'school_year_id',
        'academic_year',
        'term_start',
        'term_end',
        'position_title',
        'source_type',
        'source_id',
        'status',
        'is_primary_admin',
        'approved_by',
        'approved_at',
        'revoked_by',
        'revoked_at',
        'revocation_reason',
    ];

    protected $casts = [
        'term_start' => 'date',
        'term_end' => 'date',
        'approved_at' => 'datetime',
        'revoked_at' => 'datetime',
        'is_primary_admin' => 'boolean',
    ];

    public function scopeCurrentlyEffective(Builder $query): Builder
    {
        $today = today()->toDateString();

        return $query
            ->where('status', self::STATUS_ACTIVE)
            ->where(function (Builder $query) use ($today): void {
                $query->where('source_type', '!=', self::SOURCE_OSA_OVERRIDE)
                    ->orWhereNull('source_type')
                    ->orWhere(function (Builder $overrideQuery) use ($today): void {
                        $overrideQuery
                            ->where(function (Builder $query) use ($today): void {
                                $query->whereNull('term_start')
                                    ->orWhereDate('term_start', '<=', $today);
                            })
                            ->where(function (Builder $query) use ($today): void {
                                $query->whereNull('term_end')
                                    ->orWhereDate('term_end', '>=', $today);
                            });
                    });
            });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function college()
    {
        return $this->belongsTo(College::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function schoolYear(): BelongsTo
    {
        return $this->belongsTo(SchoolYear::class);
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function revoker()
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }
}
