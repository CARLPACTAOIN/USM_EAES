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
        Schema::create('osa_authority_overrides', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('action');
            $table->string('status')->default('active');
            $table->foreignUuid('target_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('role_name')->nullable();
            $table->foreignUuid('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->foreignUuid('college_id')->nullable()->constrained('colleges')->nullOnDelete();
            $table->foreignUuid('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignUuid('school_year_id')->nullable()->constrained('school_years')->nullOnDelete();
            $table->string('academic_year')->nullable();
            $table->date('term_start')->nullable();
            $table->date('term_end')->nullable();
            $table->string('position_title')->nullable();
            $table->text('reason');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->foreignUuid('source_assignment_id')->nullable()->constrained('admin_assignments')->nullOnDelete();
            $table->foreignUuid('projected_assignment_id')->nullable()->constrained('admin_assignments')->nullOnDelete();
            $table->foreignUuid('actor_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('acted_at');
            $table->timestamps();

            $table->index(['action', 'status'], 'osa_authority_overrides_action_status_index');
            $table->index(['target_user_id', 'status'], 'osa_authority_overrides_target_status_index');
            $table->index(['organization_id', 'school_year_id', 'status'], 'osa_authority_overrides_org_sy_status_index');
            $table->index(['actor_id', 'acted_at'], 'osa_authority_overrides_actor_acted_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('osa_authority_overrides');
    }
};
