<?php

namespace App\Models;

use Database\Factories\SchoolYearFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SchoolYear extends Model
{
    /** @use HasFactory<SchoolYearFactory> */
    use HasFactory, HasUuids;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'label',
        'starts_on',
        'ends_on',
        'status',
        'activated_at',
        'activated_by',
        'closed_at',
        'closed_by',
        'transition_started_at',
        'transition_ends_at',
        'transition_opened_by',
        'transition_closed_at',
        'transition_closed_by',
    ];

    protected $attributes = [
        'status' => self::STATUS_DRAFT,
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'activated_at' => 'datetime',
            'closed_at' => 'datetime',
            'transition_started_at' => 'datetime',
            'transition_ends_at' => 'date',
            'transition_closed_at' => 'datetime',
        ];
    }

    public function activatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'activated_by');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function transitionOpenedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'transition_opened_by');
    }

    public function transitionClosedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'transition_closed_by');
    }

    public function isTransitionOpen(): bool
    {
        return $this->transition_started_at !== null && $this->transition_closed_at === null;
    }
}
