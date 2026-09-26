<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowTaskAssignmentHistory extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'workflow_task_assignment_history';

    protected $fillable = [
        'workflow_task_id',
        'former_assignee_id',
        'new_assignee_id',
        'actor_id',
        'reason',
        'reassigned_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'reassigned_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(ProposalWorkflowTask::class, 'workflow_task_id');
    }

    public function formerAssignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'former_assignee_id');
    }

    public function newAssignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'new_assignee_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
