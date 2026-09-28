<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PendingStudentLink extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'event_id',
        'event_participation_scope_id',
        'organization_id',
        'raw_scan_id',
        'qr_code_value',
        'status',
        'resolved_student_id',
        'resolved_by',
        'flagged_by',
        'notes',
        'resolved_at',
        'flagged_at',
    ];

    protected function casts(): array
    {
        return [
            'resolved_at' => 'datetime',
            'flagged_at' => 'datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function participationScope(): BelongsTo
    {
        return $this->belongsTo(EventParticipationScope::class, 'event_participation_scope_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function rawScan(): BelongsTo
    {
        return $this->belongsTo(RawScan::class);
    }

    public function resolvedStudent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_student_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function flaggedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'flagged_by');
    }
}
