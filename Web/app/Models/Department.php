<?php

namespace App\Models;

use Database\Factories\DepartmentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Department extends Model
{
    /** @use HasFactory<DepartmentFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'college_id',
        'name',
        'code',
        'status',
    ];

    protected $attributes = [
        'status' => 'active',
    ];

    public function college(): BelongsTo
    {
        return $this->belongsTo(College::class);
    }
}
