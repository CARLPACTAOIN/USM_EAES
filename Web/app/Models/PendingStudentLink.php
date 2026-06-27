<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PendingStudentLink extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'event_id',
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

    protected $casts = [
        'resolved_at' => 'datetime',
        'flagged_at' => 'datetime',
    ];

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function rawScan()
    {
        return $this->belongsTo(RawScan::class);
    }

    public function resolvedStudent()
    {
        return $this->belongsTo(User::class, 'resolved_student_id');
    }

    public function resolvedBy()
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function flaggedBy()
    {
        return $this->belongsTo(User::class, 'flagged_by');
    }
}
