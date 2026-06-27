<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Evaluation extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'event_id',
        'student_id',
        'section_scores',
        'open_comment',
        'sentiment', // "positive", "neutral", "negative", "unprocessed"
        'sentiment_score',
        'submitted_at',
    ];

    protected $casts = [
        'section_scores' => 'array', // automatically casts JSONB to array
        'sentiment_score' => 'float',
        'submitted_at' => 'datetime',
    ];

    public function event()
    {
        return $this->belongsTo(Event::class);
    }

    public function student()
    {
        return $this->belongsTo(User::class, 'student_id');
    }
}
