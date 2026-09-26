<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApprovalTrail extends Model
{
    use HasUuids;

    protected $fillable = [
        'proposal_id',
        'workflow_run_id',
        'workflow_task_id',
        'actor_id',
        'decision',
        'remarks',
        'reference_id',
        'previous_status',
        'resulting_status',
        'assigned_role',
        'assigned_scope',
        'decided_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'decided_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new DomainException('Approval Trails are immutable.');
        });

        static::deleting(function (): void {
            throw new DomainException('Approval Trails are immutable.');
        });
    }

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'proposal_id');
    }

    public function workflowRun(): BelongsTo
    {
        return $this->belongsTo(ProposalWorkflowRun::class, 'workflow_run_id');
    }

    public function workflowTask(): BelongsTo
    {
        return $this->belongsTo(ProposalWorkflowTask::class, 'workflow_task_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
