<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Evaluation extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'event_id',
        'student_id',
        'evaluation_form_version_id',
        'section_scores',
        'open_comment',
        'sentiment', // "positive", "neutral", "negative", "unprocessed"
        'sentiment_score',
        'submitted_at',
    ];

    protected function casts(): array
    {
        return [
            'section_scores' => 'array',
            'sentiment_score' => 'float',
            'submitted_at' => 'datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function evaluationFormVersion(): BelongsTo
    {
        return $this->belongsTo(EvaluationFormVersion::class);
    }

    public function scopePendingSentimentComments(Builder $query): Builder
    {
        return $query
            ->where('sentiment', 'unprocessed')
            ->whereNotNull('open_comment')
            ->whereRaw('length(trim(open_comment)) > 0');
    }
}
