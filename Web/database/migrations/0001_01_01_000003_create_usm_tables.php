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
        // 1. universities
        Schema::create('universities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('domain')->unique();
            $table->timestamps();
        });

        // 2. colleges
        Schema::create('colleges', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('university_id');
            $table->string('name');
            $table->string('code')->unique();
            $table->timestamps();

            $table->foreign('university_id')->references('id')->on('universities')->onDelete('cascade');
        });

        // 3. organizations
        Schema::create('organizations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('college_id')->nullable();
            $table->string('name');
            $table->string('acronym');
            $table->string('type'); // Enum: "society", "usg", "lsg", "aro"
            $table->timestamps();

            $table->foreign('college_id')->references('id')->on('colleges')->onDelete('cascade');
        });

        // Add foreign key constraint to users table created in earlier migration
        Schema::table('users', function (Blueprint $table) {
            $table->foreign('organization_id')->references('id')->on('organizations')->onDelete('set null');
        });

        // 4. events
        Schema::create('events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->uuid('parent_event_id')->nullable();
            $table->string('title');
            $table->string('status'); // Enum: "draft", "submitted", "under_review", "approved", "rejected", "completed"
            $table->date('start_date');
            $table->date('end_date');
            $table->string('location_type')->default('on-campus');
            $table->string('location_details')->nullable();
            $table->jsonb('target_demographics')->nullable();
            $table->jsonb('budget_allocations')->nullable();
            $table->integer('society_late_threshold_min')->default(15);
            $table->integer('general_competition_threshold_min')->default(30);
            $table->integer('left_early_buffer_min')->default(15);
            $table->boolean('evaluation_open')->default(false);
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->onDelete('cascade');
        });

        // PostgreSQL is stricter about self-referencing foreign keys during CREATE TABLE,
        // so attach this constraint after the events table exists.
        Schema::table('events', function (Blueprint $table) {
            $table->foreign('parent_event_id')->references('id')->on('events')->onDelete('set null');
        });

        // 5. event_days
        Schema::create('event_days', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('event_id');
            $table->integer('day_number');
            $table->date('date');
            $table->time('start_time');
            $table->time('end_time');
            $table->timestamps();

            $table->foreign('event_id')->references('id')->on('events')->onDelete('cascade');
        });

        // 6. raw_scans
        Schema::create('raw_scans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('event_id');
            $table->uuid('event_day_id')->nullable();
            $table->uuid('student_id')->nullable(); // Nullable for unresolved scans
            $table->string('qr_code_value')->nullable(); // Stores raw scan QR
            $table->string('scan_type'); // Enum: "time_in", "time_out"
            $table->timestamp('scanned_at');
            $table->string('device_id');
            $table->boolean('manual_entry')->default(false);
            $table->string('dedup_key')->unique();
            $table->timestamps();

            $table->foreign('event_id')->references('id')->on('events')->onDelete('cascade');
            $table->foreign('event_day_id')->references('id')->on('event_days')->onDelete('set null');
            $table->foreign('student_id')->references('id')->on('users')->onDelete('set null');
        });

        // 7. attendance_records
        Schema::create('attendance_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('event_id');
            $table->uuid('event_day_id')->nullable();
            $table->uuid('student_id');
            $table->timestamp('time_in')->nullable();
            $table->timestamp('time_out')->nullable();
            $table->string('society_status'); // Enum: "present_on_time", "late", "late_cutoff", "absent"
            $table->string('competition_status'); // Enum: "present_on_time", "late", "late_cutoff", "absent"
            $table->boolean('left_early')->default(false);
            $table->boolean('valid')->default(false);
            $table->boolean('force_validated')->default(false);
            $table->uuid('validated_by')->nullable();
            $table->timestamps();

            $table->foreign('event_id')->references('id')->on('events')->onDelete('cascade');
            $table->foreign('event_day_id')->references('id')->on('event_days')->onDelete('set null');
            $table->foreign('student_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('validated_by')->references('id')->on('users')->onDelete('set null');
        });

        // 8. evaluations
        Schema::create('evaluations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('event_id');
            $table->uuid('student_id');
            $table->jsonb('section_scores');
            $table->text('open_comment')->nullable();
            $table->string('sentiment')->nullable(); // Enum: "positive", "neutral", "negative", "unprocessed"
            $table->float('sentiment_score')->nullable();
            $table->timestamp('submitted_at');
            $table->timestamps();

            $table->foreign('event_id')->references('id')->on('events')->onDelete('cascade');
            $table->foreign('student_id')->references('id')->on('users')->onDelete('cascade');
        });

        // 9. audit_logs
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('target_id');
            $table->uuid('admin_id');
            $table->string('action');
            $table->jsonb('details')->nullable();
            $table->timestamps();

            $table->foreign('admin_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
        });

        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('evaluations');
        Schema::dropIfExists('attendance_records');
        Schema::dropIfExists('raw_scans');
        Schema::dropIfExists('event_days');
        Schema::dropIfExists('events');
        Schema::dropIfExists('organizations');
        Schema::dropIfExists('colleges');
        Schema::dropIfExists('universities');
    }
};
