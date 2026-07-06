<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EventScannerAssignment extends Model
{
    use HasFactory, HasUuids;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_REVOKED = 'revoked';
    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'event_id',
        'event_participation_scope_id',
        'audience_scope_type',
        'scanner_user_id',
        'assigned_by_user_id',
        'label',
        'status',
        'revoked_at',
        'revoked_by_user_id',
        'revocation_reason',
        'expires_at',
        'max_devices',
        'notes',
        'last_activated_at',
    ];

    protected $casts = [
        'revoked_at' => 'datetime',
        'expires_at' => 'datetime',
        'last_activated_at' => 'datetime',
        'max_devices' => 'integer',
    ];

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function participationScope()
    {
        return $this->belongsTo(EventParticipationScope::class, 'event_participation_scope_id');
    }

    public function scannerUser()
    {
        return $this->belongsTo(User::class, 'scanner_user_id');
    }

    public function assignedBy()
    {
        return $this->belongsTo(User::class, 'assigned_by_user_id');
    }

    public function revokedBy()
    {
        return $this->belongsTo(User::class, 'revoked_by_user_id');
    }

    public function scannerSessions()
    {
        return $this->hasMany(ScannerSession::class, 'assignment_id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE)
            ->where(function ($query): void {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
    }

    public function isActive(): bool
    {
        if ($this->status !== self::STATUS_ACTIVE) {
            return false;
        }

        return !$this->expires_at || $this->expires_at->isFuture();
    }

    public function markRevoked(User $revokedBy, ?string $reason = null): void
    {
        $this->update([
            'status' => self::STATUS_REVOKED,
            'revoked_at' => now(),
            'revoked_by_user_id' => $revokedBy->id,
            'revocation_reason' => $reason,
        ]);
    }

    public function markExpired(): void
    {
        $this->update([
            'status' => self::STATUS_EXPIRED,
        ]);
    }
}
