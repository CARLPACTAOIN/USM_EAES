<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Program extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'college_id',
        'department_id',
        'name',
        'code',
    ];

    public function college(): BelongsTo
    {
        return $this->belongsTo(College::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function resolvedCollege(): ?College
    {
        return $this->department?->college ?? $this->college;
    }

    public function organizations()
    {
        return $this->belongsToMany(Organization::class, 'organization_programs')
            ->using(OrganizationProgram::class)
            ->withTimestamps();
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }
}
