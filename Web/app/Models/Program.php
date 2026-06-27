<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Program extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'college_id',
        'name',
        'code',
    ];

    public function college()
    {
        return $this->belongsTo(College::class);
    }

    public function organizations()
    {
        return $this->belongsToMany(Organization::class, 'organization_programs');
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }
}
