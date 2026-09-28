<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    public function programs(): BelongsToMany
    {
        return $this->belongsToMany(Program::class, 'organization_programs')
            ->using(OrganizationProgram::class)
            ->withTimestamps();
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(OrganizationMembership::class);
    }

    public function participationScopes(): HasMany
    {
        return $this->hasMany(EventParticipationScope::class);
    }

    public function adminAssignments(): HasMany
    {
        return $this->hasMany(AdminAssignment::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    public function officerRosters(): HasMany
    {
        return $this->hasMany(OrganizationOfficerRoster::class);
    }

    public function officerChangeRequests(): HasMany
    {
        return $this->hasMany(OfficerChangeRequest::class);
    }
}
