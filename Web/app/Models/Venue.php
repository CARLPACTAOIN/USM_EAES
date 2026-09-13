<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Venue extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'name', 'campus_location', 'college_id', 'managing_office',
        'inquiry_instructions', 'is_active', 'retired_at',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'retired_at' => 'datetime'];
    }

    public function college(): BelongsTo
    {
        return $this->belongsTo(College::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    public function permitVersions(): HasMany
    {
        return $this->hasMany(EventPermitVersion::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function displayName(): string
    {
        return $this->name.' — '.$this->campus_location;
    }
}
