<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventPermitVersion extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'event_id', 'version_number', 'venue_id', 'venue_name_snapshot',
        'venue_location_snapshot', 'location_type', 'location_details',
        'confirmation_office', 'confirmation_date', 'confirmation_reference_number',
        'confirmation_document_path', 'confirmation_document_original_name',
        'ppa_document_path', 'ppa_document_original_name', 'amendment_reason',
        'approved_by', 'approved_at', 'superseded_at',
    ];

    protected function casts(): array
    {
        return [
            'version_number' => 'integer',
            'confirmation_date' => 'date',
            'approved_at' => 'datetime',
            'superseded_at' => 'datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
