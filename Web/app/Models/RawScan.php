<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RawScan extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'event_id',
        'event_participation_scope_id',
        'event_day_id',
        'event_day_session_id',
        'scanner_session_id',
        'scanner_user_id',
        'captured_by_organization_id',
        'captured_by_admin_scope',
        'student_id',
        'qr_code_value',
        'scan_type', // "time_in", "time_out"
        'scanned_at',
        'server_received_at',
        'processed_at',
        'device_id',
        'manual_entry',
        'dedup_key',
        'rejection_reason',
    ];

    protected $casts = [
        'scanned_at' => 'datetime',
        'server_received_at' => 'datetime',
        'processed_at' => 'datetime',
        'manual_entry' => 'boolean',
    ];

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function participationScope()
    {
        return $this->belongsTo(EventParticipationScope::class, 'event_participation_scope_id');
    }

    public function eventDay()
    {
        return $this->belongsTo(EventDay::class);
    }

    public function eventDaySession()
    {
        return $this->belongsTo(EventDaySession::class);
    }

    public function student()
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function scannerSession()
    {
        return $this->belongsTo(ScannerSession::class);
    }

    public function scannerUser()
    {
        return $this->belongsTo(User::class, 'scanner_user_id');
    }

    public function capturedByOrganization()
    {
        return $this->belongsTo(Organization::class, 'captured_by_organization_id');
    }
}
