<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            if (! Schema::hasColumn('events', 'department_id')) {
                $table->foreignUuid('department_id')
                    ->nullable()
                    ->after('organization_id')
                    ->constrained('departments')
                    ->nullOnDelete();

                $table->index(['department_id', 'status'], 'events_department_id_status_index');
            }
        });

        Schema::table('event_participation_scopes', function (Blueprint $table): void {
            if (! Schema::hasColumn('event_participation_scopes', 'school_year_id')) {
                $table->foreignUuid('school_year_id')
                    ->nullable()
                    ->after('event_id')
                    ->constrained('school_years')
                    ->nullOnDelete();

                $table->index(['school_year_id', 'status'], 'eps_school_year_status_index');
            }

            if (! Schema::hasColumn('event_participation_scopes', 'department_id')) {
                $table->foreignUuid('department_id')
                    ->nullable()
                    ->after('college_id')
                    ->constrained('departments')
                    ->nullOnDelete();

                $table->index(['department_id', 'status'], 'eps_department_status_index');
            }
        });

        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            if (DB::getDriverName() === 'sqlite') {
                DB::statement('DROP INDEX IF EXISTS eps_active_organizer_unique');
                DB::statement('DROP INDEX IF EXISTS eps_active_participant_org_unique');
                DB::statement('DROP INDEX IF EXISTS eps_active_participant_college_unique');
                DB::statement('DROP INDEX IF EXISTS eps_active_linked_event_unique');

                DB::statement("CREATE UNIQUE INDEX eps_active_organizer_unique ON event_participation_scopes (event_id) WHERE status = 'active' AND participation_role = 'organizer'");
                DB::statement("CREATE UNIQUE INDEX eps_active_participant_org_unique ON event_participation_scopes (event_id, organization_id) WHERE status = 'active' AND participation_role = 'participant' AND organization_id IS NOT NULL");
                DB::statement("CREATE UNIQUE INDEX eps_active_participant_college_unique ON event_participation_scopes (event_id, college_id) WHERE status = 'active' AND participation_role = 'participant' AND participant_type = 'college' AND organization_id IS NULL AND college_id IS NOT NULL");
                DB::statement("CREATE UNIQUE INDEX eps_active_linked_event_unique ON event_participation_scopes (event_id, linked_event_id) WHERE status = 'active' AND participation_role = 'participant' AND linked_event_id IS NOT NULL");
            }

            DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS eps_active_participant_dept_unique ON event_participation_scopes (event_id, department_id) WHERE status = 'active' AND participation_role = 'participant' AND participant_type = 'department' AND department_id IS NOT NULL");
        }

        Schema::table('event_scanner_assignments', function (Blueprint $table): void {
            if (! Schema::hasColumn('event_scanner_assignments', 'school_year_id')) {
                $table->foreignUuid('school_year_id')
                    ->nullable()
                    ->after('event_id')
                    ->constrained('school_years')
                    ->nullOnDelete();

                $table->index(['school_year_id', 'status'], 'esa_school_year_status_index');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement('DROP INDEX IF EXISTS eps_active_participant_dept_unique');
        }

        Schema::table('event_scanner_assignments', function (Blueprint $table): void {
            if (Schema::hasColumn('event_scanner_assignments', 'school_year_id')) {
                $table->dropIndex('esa_school_year_status_index');
                $table->dropForeign(['school_year_id']);
                $table->dropColumn('school_year_id');
            }
        });

        Schema::table('event_participation_scopes', function (Blueprint $table): void {
            if (Schema::hasColumn('event_participation_scopes', 'department_id')) {
                $table->dropIndex('eps_department_status_index');
                $table->dropForeign(['department_id']);
                $table->dropColumn('department_id');
            }

            if (Schema::hasColumn('event_participation_scopes', 'school_year_id')) {
                $table->dropIndex('eps_school_year_status_index');
                $table->dropForeign(['school_year_id']);
                $table->dropColumn('school_year_id');
            }
        });

        Schema::table('events', function (Blueprint $table): void {
            if (Schema::hasColumn('events', 'department_id')) {
                $table->dropIndex('events_department_id_status_index');
                $table->dropForeign(['department_id']);
                $table->dropColumn('department_id');
            }
        });
    }
};
