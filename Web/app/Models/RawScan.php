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
        'event_day_id',
        'student_id',
        'qr_code_value',
        'scan_type', // "time_in", "time_out"
        'scanned_at',
        'device_id',
        'manual_entry',
        'dedup_key',
    ];

    protected $casts = [
        'scanned_at' => 'datetime',
        'manual_entry' => 'boolean',
    ];

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function eventDay()
    {
        return $this->belongsTo(EventDay::class);
    }

    public function student()
    {
        return $this->belongsTo(User::class, 'student_id');
    }
}
