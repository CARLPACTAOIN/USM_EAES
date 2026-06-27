<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class University extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'name',
        'domain',
    ];

    public function colleges()
    {
        return $this->hasMany(College::class);
    }
}
