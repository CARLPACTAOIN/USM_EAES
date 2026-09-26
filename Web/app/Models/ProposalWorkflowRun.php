<?php

namespace App\Models;

use Database\Factories\ProposalWorkflowRunFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProposalWorkflowRun extends Model
{
    /** @use HasFactory<ProposalWorkflowRunFactory> */
    use HasFactory, HasUuids;

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_REVISION_REQUESTED = 'revision_requested';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'event_id',
        'template_version_id',
        'status',
        'started_by',
        'started_at',
        'submitted_at',
        'completed_at',
        'cancelled_at',
        'metadata',
    ];

    protected $attributes = [
        'status' => self::STATUS_IN_PROGRESS,
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'submitted_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'event_id');
    }

    public function templateVersion(): BelongsTo
    {
        return $this->belongsTo(ProposalProcessTemplateVersion::class, 'template_version_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(ProposalWorkflowTask::class, 'workflow_run_id')->orderBy('display_order');
    }

    public function approvalTrails(): HasMany
    {
        return $this->hasMany(ApprovalTrail::class, 'workflow_run_id')
            ->latest('decided_at');
    }

    public function startedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }
}
