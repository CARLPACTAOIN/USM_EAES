<?php

namespace App\Models;

use Database\Factories\ProposalWorkflowTaskFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProposalWorkflowTask extends Model
{
    /** @use HasFactory<ProposalWorkflowTaskFactory> */
    use HasFactory, HasUuids;

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_AVAILABLE = 'available';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_REVISION_REQUESTED = 'revision_requested';

    public const STATUS_NOT_REQUIRED = 'not_required';

    public const DECISION_APPROVED = 'approved';

    public const DECISION_REJECTED = 'rejected';

    public const DECISION_REVISION_REQUESTED = 'revision_requested';

    protected $fillable = [
        'workflow_run_id',
        'process_step_id',
        'status',
        'display_order',
        'step_key',
        'step_type',
        'title',
        'description',
        'assigned_user_id',
        'assigned_role',
        'assigned_scope',
        'available_at',
        'completed_at',
        'decision',
        'remarks',
        'decided_by',
        'decided_at',
        'metadata',
    ];

    protected $attributes = [
        'status' => self::STATUS_BLOCKED,
    ];

    protected function casts(): array
    {
        return [
            'display_order' => 'integer',
            'available_at' => 'datetime',
            'completed_at' => 'datetime',
            'decided_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function workflowRun(): BelongsTo
    {
        return $this->belongsTo(ProposalWorkflowRun::class, 'workflow_run_id');
    }

    public function processStep(): BelongsTo
    {
        return $this->belongsTo(ProposalProcessStep::class, 'process_step_id');
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
