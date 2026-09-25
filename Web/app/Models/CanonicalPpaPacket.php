<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CanonicalPpaPacket extends Model
{
    use HasFactory, HasUuids;

    public const ROLE_ORGANIZER = 'organizer';

    public const ROLE_CO_ORGANIZER = 'co-organizer';

    public const ROLE_PARTICIPANT = 'participant';

    protected $attributes = [
        'activity_role' => self::ROLE_ORGANIZER,
    ];

    protected $fillable = [
        'event_id',
        'activity_role',
        'proposal_category',
        'title',
        'proponent_name',
        'proponent_position',
        'proponent_organization',
        'proponent_email',
        'proponent_contact_number',
        'implementing_office',
        'collaborating_office',
        'source_of_fund',
        'budget_cost',
        'start_date',
        'end_date',
        'location_type',
        'venue_id',
        'location_details',
        'venue_confirmation_office',
        'venue_confirmation_date',
        'venue_confirmation_reference_number',
        'rationale',
        'general_objective',
        'specific_objectives',
        'methodology',
        'major_activities',
        'program_activities',
        'expected_output',
        'beneficiaries',
        'budget_breakdown',
        'schedule_summary',
        'venue_summary',
        'prepared_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'budget_cost' => 'decimal:2',
            'start_date' => 'date',
            'end_date' => 'date',
            'venue_confirmation_date' => 'date',
            'specific_objectives' => 'array',
            'major_activities' => 'array',
            'program_activities' => 'array',
            'budget_breakdown' => 'array',
            'schedule_summary' => 'array',
            'venue_summary' => 'array',
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

    public function preparedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by_user_id');
    }
}
