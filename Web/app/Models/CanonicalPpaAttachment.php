<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CanonicalPpaAttachment extends Model
{
    use HasFactory, HasUuids;

    public const TYPE_PROPOSAL = 'proposal';

    public const TYPE_VENUE_CONFIRMATION = 'venue_confirmation';

    public const TYPE_SUPPORTING = 'supporting';

    protected $fillable = [
        'canonical_ppa_packet_id',
        'attachment_type',
        'path',
        'original_name',
        'mime_type',
        'size_bytes',
        'uploaded_by_user_id',
    ];

    protected $hidden = [
        'path',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
        ];
    }

    public function packet(): BelongsTo
    {
        return $this->belongsTo(CanonicalPpaPacket::class, 'canonical_ppa_packet_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}
