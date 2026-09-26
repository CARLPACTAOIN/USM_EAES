<?php

namespace App\Models;

use Database\Factories\ProposalProcessTemplateVersionFactory;
use DomainException;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProposalProcessTemplateVersion extends Model
{
    /** @use HasFactory<ProposalProcessTemplateVersionFactory> */
    use HasFactory, HasUuids;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_RETIRED = 'retired';

    protected $fillable = [
        'template_id',
        'version_number',
        'status',
        'summary',
        'published_at',
        'published_by',
        'retired_at',
        'retired_by',
    ];

    protected $attributes = [
        'status' => self::STATUS_DRAFT,
    ];

    protected function casts(): array
    {
        return [
            'version_number' => 'integer',
            'published_at' => 'datetime',
            'retired_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (ProposalProcessTemplateVersion $version): void {
            if ($version->getOriginal('status') === self::STATUS_PUBLISHED) {
                $dirty = array_keys($version->getDirty());
                $allowedRetirementFields = ['status', 'retired_at', 'retired_by', 'updated_at'];
                $isRetirement = empty(array_diff($dirty, $allowedRetirementFields)) && $version->status === self::STATUS_RETIRED;

                if (! $isRetirement) {
                    throw new DomainException('Published template versions are immutable.');
                }
            }
        });

        static::deleting(function (ProposalProcessTemplateVersion $version): void {
            if ($version->status === self::STATUS_PUBLISHED) {
                throw new DomainException('Published template versions cannot be deleted.');
            }
        });
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ProposalProcessTemplate::class, 'template_id');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(ProposalProcessStep::class, 'template_version_id')->orderBy('display_order');
    }

    public function applicabilityRules(): HasMany
    {
        return $this->hasMany(ProposalTemplateApplicabilityRule::class, 'template_version_id')->orderByDesc('priority');
    }

    public function workflowRuns(): HasMany
    {
        return $this->hasMany(ProposalWorkflowRun::class, 'template_version_id');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function retirer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'retired_by');
    }
}
