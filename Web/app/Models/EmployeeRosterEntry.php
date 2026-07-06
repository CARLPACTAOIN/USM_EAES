<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EmployeeRosterEntry extends Model
{
    use HasFactory, HasUuids;

    public const STATUS_ACTIVE = 'active';

    protected $fillable = [
        'user_id',
        'employee_id',
        'qr_code_value',
        'email',
        'name',
        'user_type',
        'office_department',
        'college_id',
        'position_title',
        'status',
        'verified_by',
        'verified_at',
    ];

    protected $casts = [
        'verified_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function college()
    {
        return $this->belongsTo(College::class);
    }

    public function verifiedBy()
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
