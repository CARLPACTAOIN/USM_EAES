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
        Schema::create('organization_officer_rosters', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignUuid('school_year_id')->constrained('school_years')->cascadeOnDelete();
            $table->string('status')->default('draft');
            $table->string('evidence_file_path')->nullable();
            $table->string('evidence_file_name')->nullable();
            $table->text('attestation_text')->nullable();
            $table->json('evidence_metadata')->nullable();
            $table->foreignUuid('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignUuid('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->text('verification_remarks')->nullable();
            $table->text('rejection_remarks')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'school_year_id'], 'officer_rosters_org_sy_index');
            $table->index(['organization_id', 'status'], 'officer_rosters_org_status_index');
        });

        Schema::create('organization_officers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('officer_roster_id')->constrained('organization_officer_rosters')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('position_key');
            $table->string('position_title')->nullable();
            $table->string('status')->default('active');
            $table->date('effective_start')->nullable();
            $table->date('effective_end')->nullable();
            $table->uuid('replacement_officer_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['officer_roster_id', 'position_key'], 'organization_officers_roster_position_index');
            $table->index(['user_id', 'status'], 'organization_officers_user_status_index');
        });

        // PostgreSQL requires the table and primary key to exist before adding self-referencing FK
        Schema::table('organization_officers', function (Blueprint $table): void {
            $table->foreign('replacement_officer_id')->references('id')->on('organization_officers')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('organization_officers');
        Schema::dropIfExists('organization_officer_rosters');
    }
};
