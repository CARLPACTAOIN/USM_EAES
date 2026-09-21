<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_day_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('event_day_id');
            $table->integer('session_number');
            $table->string('name');
            $table->time('start_time');
            $table->time('end_time');
            $table->boolean('required_for_attendance')->default(true);
            $table->timestamps();

            $table->foreign('event_day_id')->references('id')->on('event_days')->onDelete('cascade');
            $table->unique(['event_day_id', 'session_number']);
            $table->index(['event_day_id', 'required_for_attendance']);
        });

        Schema::table('raw_scans', function (Blueprint $table): void {
            $table->uuid('event_day_session_id')->nullable()->after('event_day_id');
            $table->foreign('event_day_session_id')->references('id')->on('event_day_sessions')->onDelete('set null');
            $table->index(['event_day_session_id', 'student_id']);
        });

        Schema::table('attendance_records', function (Blueprint $table): void {
            $table->uuid('event_day_session_id')->nullable()->after('event_day_id');
            $table->string('completion_status')->default('partial')->after('left_early');
            $table->foreign('event_day_session_id')->references('id')->on('event_day_sessions')->onDelete('set null');
            $table->index(['event_day_session_id', 'student_id']);
        });

        $this->replaceAttendanceIdentityIndexes();
    }

    public function down(): void
    {
        $this->restoreDayAttendanceIdentityIndexes();

        Schema::table('attendance_records', function (Blueprint $table): void {
            $table->dropForeign(['event_day_session_id']);
            $table->dropIndex(['event_day_session_id', 'student_id']);
            $table->dropColumn(['event_day_session_id', 'completion_status']);
        });

        Schema::table('raw_scans', function (Blueprint $table): void {
            $table->dropForeign(['event_day_session_id']);
            $table->dropIndex(['event_day_session_id', 'student_id']);
            $table->dropColumn('event_day_session_id');
        });

        Schema::dropIfExists('event_day_sessions');
    }

    private function replaceAttendanceIdentityIndexes(): void
    {
        DB::statement('DROP INDEX IF EXISTS eaes_attendance_legacy_identity_unique');
        DB::statement('DROP INDEX IF EXISTS eaes_attendance_scoped_legacy_day_identity_unique');
        DB::statement('DROP INDEX IF EXISTS eaes_attendance_legacy_day_identity_unique');
        DB::statement('DROP INDEX IF EXISTS eaes_attendance_scoped_day_identity_unique');

        DB::statement('CREATE UNIQUE INDEX eaes_attendance_scoped_session_identity_unique ON attendance_records (event_id, event_day_session_id, student_id, event_participation_scope_id) WHERE event_day_session_id IS NOT NULL AND event_participation_scope_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX eaes_attendance_legacy_session_identity_unique ON attendance_records (event_id, event_day_session_id, student_id) WHERE event_day_session_id IS NOT NULL AND event_participation_scope_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX eaes_attendance_scoped_day_identity_unique ON attendance_records (event_id, event_day_id, student_id, event_participation_scope_id) WHERE event_day_session_id IS NULL AND event_day_id IS NOT NULL AND event_participation_scope_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX eaes_attendance_legacy_day_identity_unique ON attendance_records (event_id, event_day_id, student_id) WHERE event_day_session_id IS NULL AND event_day_id IS NOT NULL AND event_participation_scope_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX eaes_attendance_scoped_legacy_day_identity_unique ON attendance_records (event_id, student_id, event_participation_scope_id) WHERE event_day_session_id IS NULL AND event_day_id IS NULL AND event_participation_scope_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX eaes_attendance_legacy_identity_unique ON attendance_records (event_id, student_id) WHERE event_day_session_id IS NULL AND event_day_id IS NULL AND event_participation_scope_id IS NULL');
    }

    private function restoreDayAttendanceIdentityIndexes(): void
    {
        DB::statement('DROP INDEX IF EXISTS eaes_attendance_legacy_identity_unique');
        DB::statement('DROP INDEX IF EXISTS eaes_attendance_scoped_legacy_day_identity_unique');
        DB::statement('DROP INDEX IF EXISTS eaes_attendance_legacy_day_identity_unique');
        DB::statement('DROP INDEX IF EXISTS eaes_attendance_scoped_day_identity_unique');
        DB::statement('DROP INDEX IF EXISTS eaes_attendance_legacy_session_identity_unique');
        DB::statement('DROP INDEX IF EXISTS eaes_attendance_scoped_session_identity_unique');

        DB::statement('CREATE UNIQUE INDEX eaes_attendance_scoped_day_identity_unique ON attendance_records (event_id, event_day_id, student_id, event_participation_scope_id) WHERE event_day_id IS NOT NULL AND event_participation_scope_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX eaes_attendance_legacy_day_identity_unique ON attendance_records (event_id, event_day_id, student_id) WHERE event_day_id IS NOT NULL AND event_participation_scope_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX eaes_attendance_scoped_legacy_day_identity_unique ON attendance_records (event_id, student_id, event_participation_scope_id) WHERE event_day_id IS NULL AND event_participation_scope_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX eaes_attendance_legacy_identity_unique ON attendance_records (event_id, student_id) WHERE event_day_id IS NULL AND event_participation_scope_id IS NULL');
    }
};
