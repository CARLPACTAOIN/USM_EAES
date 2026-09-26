<?php

namespace App\Models;

use Database\Factories\OsaAuthorityOverrideFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OsaAuthorityOverride extends Model
{
    /** @use HasFactory<OsaAuthorityOverrideFactory> */
    use HasFactory, HasUuids;

    public const ACTION_GRANT = 'grant';

    public const ACTION_REPLACE = 'replace';

    public const ACTION_LOCK = 'lock';

    public const ACTION_REVOKE = 'revoke';

    public const STATUS_ACTIVE = 'active';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => self::STATUS_ACTIVE,
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'action',
        'status',
        'target_user_id',
        'role_name',
        'organization_id',
        'college_id',
        'department_id',
        'school_year_id',
        'academic_year',
        'term_start',
        'term_end',
        'position_title',
        'reason',
        'starts_at',
        'expires_at',
        'source_assignment_id',
        'projected_assignment_id',
        'actor_id',
        'acted_at',
    ];

    protected function casts(): array
    {
        return [
            'term_start' => 'date',
            'term_end' => 'date',
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'acted_at' => 'datetime',
        ];
    }

    /**
     * @return list<string>
     */
    public static function actions(): array
    {
        return [
            self::ACTION_GRANT,
            self::ACTION_REPLACE,
            self::ACTION_LOCK,
            self::ACTION_REVOKE,
        ];
    }

    public function scopeCurrentlyEffective(Builder $query): Builder
    {
        $now = now();

        return $query
            ->where('status', self::STATUS_ACTIVE)
            ->where(function (Builder $query) use ($now): void {
                $query->whereNull('starts_at')
                    ->orWhere('starts_at', '<=', $now);
            })
            ->where(function (Builder $query) use ($now): void {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', $now);
            });
    }

    public function targetUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
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

    public function schoolYear(): BelongsTo
    {
        return $this->belongsTo(SchoolYear::class);
    }

    public function sourceAssignment(): BelongsTo
    {
        return $this->belongsTo(AdminAssignment::class, 'source_assignment_id');
    }

    public function projectedAssignment(): BelongsTo
    {
        return $this->belongsTo(AdminAssignment::class, 'projected_assignment_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
