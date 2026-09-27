<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ScannerSession extends Model
{
    use HasFactory, HasUuids;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_REVOKED = 'revoked';

    protected $fillable = [
        'event_id',
        'assignment_id',
        'event_participation_scope_id',
        'scanner_user_id',
        'token_id',
        'issued_by',
        'label',
        'device_id',
        'device_fingerprint_hash',
        'status',
        'issued_at',
        'activated_at',
        'expires_at',
        'revoked_at',
        'revoked_by',
        'revocation_reason',
        'last_seen_at',
    ];

    protected $casts = [
        'issued_at' => 'datetime',
        'activated_at' => 'datetime',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function assignment()
    {
        return $this->belongsTo(EventScannerAssignment::class, 'assignment_id');
    }

    public function participationScope()
    {
        return $this->belongsTo(EventParticipationScope::class, 'event_participation_scope_id');
    }

    public function scannerUser()
    {
        return $this->belongsTo(User::class, 'scanner_user_id');
    }

    public function issuer()
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function rawScans()
    {
        return $this->hasMany(RawScan::class);
    }
}
