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
        Schema::create('officer_change_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('officer_roster_id')->constrained('organization_officer_rosters')->cascadeOnDelete();
            $table->foreignUuid('old_officer_id')->nullable()->constrained('organization_officers')->nullOnDelete();
            $table->foreignUuid('new_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('created_officer_id')->nullable()->constrained('organization_officers')->nullOnDelete();
            $table->string('request_type');
            $table->string('position_key');
            $table->string('position_title')->nullable();
            $table->string('status')->default('pending');
            $table->text('reason');
            $table->string('evidence_file_path')->nullable();
            $table->string('evidence_file_name')->nullable();
            $table->json('evidence_metadata')->nullable();
            $table->foreignUuid('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('requested_at')->nullable();
            $table->foreignUuid('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_remarks')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status'], 'officer_change_requests_org_status_index');
            $table->index(['officer_roster_id', 'status'], 'officer_change_requests_roster_status_index');
            $table->index(['old_officer_id'], 'officer_change_requests_old_officer_index');
            $table->index(['new_user_id'], 'officer_change_requests_new_user_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('officer_change_requests');
    }
};
