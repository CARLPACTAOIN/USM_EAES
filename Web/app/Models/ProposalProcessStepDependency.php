<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProposalProcessStepDependency extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'step_id',
        'prerequisite_step_id',
    ];

    protected static function booted(): void
    {
        static::saving(function (ProposalProcessStepDependency $dependency): void {
            if ($dependency->step?->templateVersion?->status === ProposalProcessTemplateVersion::STATUS_PUBLISHED) {
                throw new DomainException('Cannot modify dependencies of a published template version.');
            }
        });

        static::deleting(function (ProposalProcessStepDependency $dependency): void {
            if ($dependency->step?->templateVersion?->status === ProposalProcessTemplateVersion::STATUS_PUBLISHED) {
                throw new DomainException('Cannot delete dependencies of a published template version.');
            }
        });
    }

    public function step(): BelongsTo
    {
        return $this->belongsTo(ProposalProcessStep::class, 'step_id');
    }

    public function prerequisiteStep(): BelongsTo
    {
        return $this->belongsTo(ProposalProcessStep::class, 'prerequisite_step_id');
    }
}
