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
        Schema::create('proposal_process_templates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status')->default('active'); // draft, active, archived
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['status']);
        });

        Schema::create('proposal_process_template_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('template_id')->constrained('proposal_process_templates')->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('status')->default('draft'); // draft, published, retired
            $table->text('summary')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->foreignUuid('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('retired_at')->nullable();
            $table->foreignUuid('retired_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['template_id', 'version_number']);
            $table->index(['status']);
        });

        Schema::create('proposal_process_steps', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('template_version_id')->constrained('proposal_process_template_versions')->cascadeOnDelete();
            $table->string('step_key');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('step_type'); // endorsement, approval, clearance, intake, review
            $table->string('phase')->nullable();
            $table->unsignedInteger('display_order');
            $table->boolean('is_required')->default(true);
            $table->boolean('is_gate')->default(false);
            $table->json('condition_config')->nullable();
            $table->timestamps();

            $table->unique(['template_version_id', 'step_key']);
            $table->index(['template_version_id', 'display_order']);
        });

        Schema::create('proposal_process_step_dependencies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('step_id')->constrained('proposal_process_steps')->cascadeOnDelete();
            $table->foreignUuid('prerequisite_step_id')->constrained('proposal_process_steps')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['step_id', 'prerequisite_step_id'], 'step_prereq_unique');
        });

        Schema::create('proposal_template_applicability_rules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('template_version_id')->constrained('proposal_process_template_versions')->cascadeOnDelete();
            $table->integer('priority')->default(0);
            $table->string('organizer_role')->nullable(); // organizer, co_organizer, participant
            $table->string('organization_type')->nullable(); // society, lsg, usg, academic_unit, administrative_office
            $table->foreignUuid('college_id')->nullable()->constrained('colleges')->nullOnDelete();
            $table->string('event_domain')->nullable();
            $table->string('activity_category')->nullable();
            $table->string('location_type')->nullable(); // on_campus, off_campus, online
            $table->boolean('is_default')->default(false);
            $table->string('status')->default('active');
            $table->timestamp('effective_start')->nullable();
            $table->timestamp('effective_end')->nullable();
            $table->timestamps();

            $table->index(['is_default', 'status']);
            $table->index(['priority', 'status']);
        });

        Schema::create('proposal_workflow_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignUuid('template_version_id')->constrained('proposal_process_template_versions')->cascadeOnDelete();
            $table->string('status')->default('in_progress'); // in_progress, approved, rejected, revision_requested, cancelled
            $table->foreignUuid('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['event_id']);
            $table->index(['status']);
        });

        Schema::create('proposal_workflow_tasks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workflow_run_id')->constrained('proposal_workflow_runs')->cascadeOnDelete();
            $table->foreignUuid('process_step_id')->constrained('proposal_process_steps')->cascadeOnDelete();
            $table->string('status')->default('blocked'); // blocked, available, in_progress, approved, rejected, revision_requested, not_required
            $table->unsignedInteger('display_order');
            $table->string('step_key');
            $table->string('step_type');
            $table->string('title');
            $table->text('description')->nullable();
            $table->foreignUuid('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('assigned_role')->nullable();
            $table->string('assigned_scope')->nullable();
            $table->timestamp('available_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('decision')->nullable(); // approved, rejected, revision_requested
            $table->text('remarks')->nullable();
            $table->foreignUuid('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['workflow_run_id', 'process_step_id']);
            $table->index(['workflow_run_id', 'status']);
            $table->index(['assigned_user_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('proposal_workflow_tasks');
        Schema::dropIfExists('proposal_workflow_runs');
        Schema::dropIfExists('proposal_template_applicability_rules');
        Schema::dropIfExists('proposal_process_step_dependencies');
        Schema::dropIfExists('proposal_process_steps');
        Schema::dropIfExists('proposal_process_template_versions');
        Schema::dropIfExists('proposal_process_templates');
    }
};
