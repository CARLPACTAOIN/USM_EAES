<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EvaluationFormVersion extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'event_id',
        'title',
        'questions',
        'created_by',
        'locked_at',
    ];

    protected $casts = [
        'questions' => 'array',
        'locked_at' => 'datetime',
    ];

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
