<?php

namespace App\Models;

use Database\Factories\ProposalProcessStepFactory;
use DomainException;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProposalProcessStep extends Model
{
    /** @use HasFactory<ProposalProcessStepFactory> */
    use HasFactory, HasUuids;

    public const TYPE_ENDORSEMENT = 'endorsement';

    public const TYPE_APPROVAL = 'approval';

    public const TYPE_CLEARANCE = 'clearance';

    public const TYPE_INTAKE = 'intake';

    public const TYPE_REVIEW = 'review';

    protected $fillable = [
        'template_version_id',
        'step_key',
        'title',
        'description',
        'step_type',
        'phase',
        'display_order',
        'is_required',
        'is_gate',
        'condition_config',
    ];

    protected $attributes = [
        'is_required' => true,
        'is_gate' => false,
    ];

    protected function casts(): array
    {
        return [
            'display_order' => 'integer',
            'is_required' => 'boolean',
            'is_gate' => 'boolean',
            'condition_config' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (ProposalProcessStep $step): void {
            if ($step->templateVersion?->status === ProposalProcessTemplateVersion::STATUS_PUBLISHED) {
                throw new DomainException('Cannot modify steps of a published template version.');
            }
        });

        static::deleting(function (ProposalProcessStep $step): void {
            if ($step->templateVersion?->status === ProposalProcessTemplateVersion::STATUS_PUBLISHED) {
                throw new DomainException('Cannot delete steps of a published template version.');
            }
        });
    }

    public function templateVersion(): BelongsTo
    {
        return $this->belongsTo(ProposalProcessTemplateVersion::class, 'template_version_id');
    }

    public function dependencies(): HasMany
    {
        return $this->hasMany(ProposalProcessStepDependency::class, 'step_id');
    }

    public function prerequisiteSteps(): BelongsToMany
    {
        return $this->belongsToMany(
            ProposalProcessStep::class,
            'proposal_process_step_dependencies',
            'step_id',
            'prerequisite_step_id'
        )->withTimestamps();
    }

    public function dependentSteps(): BelongsToMany
    {
        return $this->belongsToMany(
            ProposalProcessStep::class,
            'proposal_process_step_dependencies',
            'prerequisite_step_id',
            'step_id'
        )->withTimestamps();
    }
}
