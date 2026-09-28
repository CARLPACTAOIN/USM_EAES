<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EventDaySession extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'event_day_id',
        'session_number',
        'name',
        'start_time',
        'end_time',
        'required_for_attendance',
    ];

    protected function casts(): array
    {
        return [
            'required_for_attendance' => 'boolean',
        ];
    }

    public function eventDay(): BelongsTo
    {
        return $this->belongsTo(EventDay::class);
    }

    public function rawScans(): HasMany
    {
        return $this->hasMany(RawScan::class);
    }

    public function attendanceRecords(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }
}
