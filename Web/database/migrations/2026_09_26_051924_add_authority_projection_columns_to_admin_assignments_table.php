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
        DB::statement('DROP INDEX IF EXISTS admin_assignments_active_primary_unique');

        Schema::table('admin_assignments', function (Blueprint $table) {
            $table->foreignUuid('department_id')->nullable()->after('college_id')->constrained('departments')->nullOnDelete();
            $table->foreignUuid('school_year_id')->nullable()->after('department_id')->constrained('school_years')->nullOnDelete();
            $table->string('source_type')->nullable()->after('position_title');
            $table->uuid('source_id')->nullable()->after('source_type');

            $table->index(['user_id', 'school_year_id', 'status'], 'admin_assignments_user_school_year_status_index');
            $table->index(['organization_id', 'school_year_id', 'status'], 'admin_assignments_org_school_year_status_index');
            $table->index(['source_type', 'source_id'], 'admin_assignments_source_index');
        });

        $this->createActivePrimaryIndex();
        $this->createActiveSourceIndex();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS admin_assignments_active_source_unique');
        DB::statement('DROP INDEX IF EXISTS admin_assignments_active_primary_unique');

        Schema::table('admin_assignments', function (Blueprint $table) {
            $table->dropIndex('admin_assignments_user_school_year_status_index');
            $table->dropIndex('admin_assignments_org_school_year_status_index');
            $table->dropIndex('admin_assignments_source_index');
            $table->dropForeign(['department_id']);
            $table->dropForeign(['school_year_id']);
            $table->dropColumn(['department_id', 'school_year_id', 'source_type', 'source_id']);
        });

        $this->createActivePrimaryIndex();
    }

    private function createActivePrimaryIndex(): void
    {
        $uuidZero = '00000000-0000-0000-0000-000000000000';

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("CREATE UNIQUE INDEX admin_assignments_active_primary_unique ON admin_assignments (role_name, COALESCE(organization_id::text, '{$uuidZero}'), COALESCE(college_id::text, '{$uuidZero}'), academic_year) WHERE status = 'active' AND is_primary_admin = true");

            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            DB::statement("CREATE UNIQUE INDEX admin_assignments_active_primary_unique ON admin_assignments (role_name, COALESCE(organization_id, '{$uuidZero}'), COALESCE(college_id, '{$uuidZero}'), academic_year) WHERE status = 'active' AND is_primary_admin = 1");
        }
    }

    private function createActiveSourceIndex(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("CREATE UNIQUE INDEX admin_assignments_active_source_unique ON admin_assignments (source_type, source_id) WHERE status = 'active' AND source_type IS NOT NULL AND source_id IS NOT NULL");

            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            DB::statement("CREATE UNIQUE INDEX admin_assignments_active_source_unique ON admin_assignments (source_type, source_id) WHERE status = 'active' AND source_type IS NOT NULL AND source_id IS NOT NULL");
        }
    }
};
