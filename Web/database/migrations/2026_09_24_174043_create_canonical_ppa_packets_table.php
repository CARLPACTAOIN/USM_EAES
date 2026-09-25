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
        Schema::create('canonical_ppa_packets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('event_id')->constrained()->cascadeOnDelete();
            $table->string('activity_role')->default('organizer');
            $table->string('proposal_category')->nullable();
            $table->string('title')->nullable();
            $table->string('proponent_name')->nullable();
            $table->string('proponent_position')->nullable();
            $table->string('proponent_organization')->nullable();
            $table->string('proponent_email')->nullable();
            $table->string('proponent_contact_number')->nullable();
            $table->string('implementing_office')->nullable();
            $table->string('collaborating_office')->nullable();
            $table->string('source_of_fund')->nullable();
            $table->decimal('budget_cost', 12, 2)->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('location_type')->nullable();
            $table->foreignUuid('venue_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('location_details')->nullable();
            $table->string('venue_confirmation_office')->nullable();
            $table->date('venue_confirmation_date')->nullable();
            $table->string('venue_confirmation_reference_number')->nullable();
            $table->text('rationale')->nullable();
            $table->text('general_objective')->nullable();
            $table->jsonb('specific_objectives')->nullable();
            $table->text('methodology')->nullable();
            $table->jsonb('major_activities')->nullable();
            $table->jsonb('program_activities')->nullable();
            $table->text('expected_output')->nullable();
            $table->text('beneficiaries')->nullable();
            $table->jsonb('budget_breakdown')->nullable();
            $table->jsonb('schedule_summary')->nullable();
            $table->jsonb('venue_summary')->nullable();
            $table->foreignUuid('prepared_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('event_id');
            $table->index(['activity_role', 'proposal_category']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('canonical_ppa_packets');
    }
};
