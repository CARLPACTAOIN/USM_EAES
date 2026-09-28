<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class University extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'name',
        'domain',
    ];

    public function colleges(): HasMany
    {
        return $this->hasMany(College::class);
    }
}
