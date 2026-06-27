<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Organization extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'college_id',
        'name',
        'acronym',
        'type', // "society", "usg", "lsg", "aro"
        'logo_path',
        'status',
    ];

    public function college()
    {
        return $this->belongsTo(College::class);
    }

    public function programs()
    {
        return $this->belongsToMany(Program::class, 'organization_programs');
    }

    public function users()
    {
        return $this->hasMany(User::class);
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
