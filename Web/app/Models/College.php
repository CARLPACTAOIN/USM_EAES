<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class College extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'university_id',
        'name',
        'code',
    ];

    public function university()
    {
        return $this->belongsTo(University::class);
    }

    public function organizations()
    {
        return $this->hasMany(Organization::class);
    }

    public function programs()
    {
        return $this->hasMany(Program::class);
    }

    public function venues(): HasMany
    {
        return $this->hasMany(Venue::class);
    }
}
