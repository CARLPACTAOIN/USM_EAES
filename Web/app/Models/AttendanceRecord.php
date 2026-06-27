<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AttendanceRecord extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'event_id',
        'event_day_id',
        'student_id',
        'time_in',
        'time_out',
        'society_status', // "present_on_time", "late", "late_cutoff", "absent"
        'competition_status', // "present_on_time", "late", "late_cutoff", "absent"
        'left_early',
        'valid',
        'force_validated',
        'validated_by',
    ];

    protected $casts = [
        'time_in' => 'datetime',
        'time_out' => 'datetime',
        'left_early' => 'boolean',
        'valid' => 'boolean',
        'force_validated' => 'boolean',
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

    public function validatedBy()
    {
        return $this->belongsTo(User::class, 'validated_by');
    }
}
