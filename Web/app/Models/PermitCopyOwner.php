<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PermitCopyOwner extends Model
{
    use HasFactory, HasUuids;

    public const TYPE_OSA = 'osa';

    public const TYPE_SECURITY = 'security';

    public const TYPE_ORGANIZER = 'organizer';

    protected $fillable = [
        'event_permit_version_id',
        'event_id',
        'owner_type',
        'owner_id',
        'owner_name',
        'owner_role',
        'copy_label',
        'assigned_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function permitVersion(): BelongsTo
    {
        return $this->belongsTo(EventPermitVersion::class, 'event_permit_version_id');
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
