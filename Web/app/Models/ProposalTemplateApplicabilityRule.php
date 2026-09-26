<?php

namespace App\Models;

use Database\Factories\ProposalTemplateApplicabilityRuleFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProposalTemplateApplicabilityRule extends Model
{
    /** @use HasFactory<ProposalTemplateApplicabilityRuleFactory> */
    use HasFactory, HasUuids;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    protected $fillable = [
        'template_version_id',
        'priority',
        'organizer_role',
        'organization_type',
        'college_id',
        'event_domain',
        'activity_category',
        'location_type',
        'is_default',
        'status',
        'effective_start',
        'effective_end',
    ];

    protected $attributes = [
        'priority' => 0,
        'is_default' => false,
        'status' => self::STATUS_ACTIVE,
    ];

    protected function casts(): array
    {
        return [
            'priority' => 'integer',
            'is_default' => 'boolean',
            'effective_start' => 'datetime',
            'effective_end' => 'datetime',
        ];
    }

    public function templateVersion(): BelongsTo
    {
        return $this->belongsTo(ProposalProcessTemplateVersion::class, 'template_version_id');
    }

    public function college(): BelongsTo
    {
        return $this->belongsTo(College::class, 'college_id');
    }
}
