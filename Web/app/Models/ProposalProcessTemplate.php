<?php

namespace App\Models;

use Database\Factories\ProposalProcessTemplateFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ProposalProcessTemplate extends Model
{
    /** @use HasFactory<ProposalProcessTemplateFactory> */
    use HasFactory, HasUuids;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'name',
        'description',
        'status',
        'created_by',
        'archived_at',
    ];

    protected $attributes = [
        'status' => self::STATUS_ACTIVE,
    ];

    protected function casts(): array
    {
        return [
            'archived_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ProposalProcessTemplateVersion::class, 'template_id');
    }

    public function publishedVersion(): HasOne
    {
        return $this->hasOne(ProposalProcessTemplateVersion::class, 'template_id')
            ->where('status', ProposalProcessTemplateVersion::STATUS_PUBLISHED)
            ->latestOfMany('version_number');
    }
}
