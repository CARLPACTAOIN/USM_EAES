<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Organization extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'college_id',
        'department_id',
        'name',
        'acronym',
        'type', // "society", "usg", "lsg", "academic_unit", "administrative_office", "non_academic_org", "hr_unit"
        'logo_path',
        'status',
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

    public function programs()
    {
        return $this->belongsToMany(Program::class, 'organization_programs')
            ->using(OrganizationProgram::class)
            ->withTimestamps();
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function memberships()
    {
        return $this->hasMany(OrganizationMembership::class);
    }

    public function participationScopes()
    {
        return $this->hasMany(EventParticipationScope::class);
    }

    public function adminApplications()
    {
        return $this->hasMany(AdminApplication::class);
    }

    public function adminAssignments()
    {
        return $this->hasMany(AdminAssignment::class);
    }

    public function events()
    {
        return $this->hasMany(Event::class);
    }
}
