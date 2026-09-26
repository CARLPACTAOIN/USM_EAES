<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('workflow_task_assignment_history', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workflow_task_id')->constrained('proposal_workflow_tasks')->cascadeOnDelete();
            $table->foreignUuid('former_assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('new_assignee_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason');
            $table->timestamp('reassigned_at');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['workflow_task_id', 'reassigned_at'], 'workflow_task_assignment_history_task_time_idx');
            $table->index(['new_assignee_id', 'reassigned_at'], 'workflow_task_assignment_history_new_assignee_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workflow_task_assignment_history');
    }
};
