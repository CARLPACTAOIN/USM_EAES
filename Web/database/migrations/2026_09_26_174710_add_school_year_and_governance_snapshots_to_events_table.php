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
        Schema::table('events', function (Blueprint $table) {
            $table->foreignUuid('school_year_id')->nullable()->after('organization_id')->constrained('school_years')->nullOnDelete();
            $table->string('college_name_snapshot')->nullable()->after('status');
            $table->string('department_name_snapshot')->nullable()->after('college_name_snapshot');
            $table->string('program_name_snapshot')->nullable()->after('department_name_snapshot');
            $table->string('organization_name_snapshot')->nullable()->after('program_name_snapshot');
            $table->string('proponent_name_snapshot')->nullable()->after('organization_name_snapshot');
            $table->foreignUuid('proponent_user_id')->nullable()->after('proponent_name_snapshot')->constrained('users')->nullOnDelete();
            $table->foreignUuid('org_head_user_id')->nullable()->after('proponent_user_id')->constrained('users')->nullOnDelete();
            $table->foreignUuid('dept_chair_user_id')->nullable()->after('org_head_user_id')->constrained('users')->nullOnDelete();
            $table->foreignUuid('college_dean_user_id')->nullable()->after('dept_chair_user_id')->constrained('users')->nullOnDelete();
            $table->jsonb('governance_snapshots')->nullable()->after('college_dean_user_id');

            $table->index(['school_year_id', 'organization_id'], 'events_sy_org_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropIndex('events_sy_org_index');
            $table->dropForeign(['school_year_id']);
            $table->dropForeign(['proponent_user_id']);
            $table->dropForeign(['org_head_user_id']);
            $table->dropForeign(['dept_chair_user_id']);
            $table->dropForeign(['college_dean_user_id']);
            $table->dropColumn([
                'school_year_id',
                'college_name_snapshot',
                'department_name_snapshot',
                'program_name_snapshot',
                'organization_name_snapshot',
                'proponent_name_snapshot',
                'proponent_user_id',
                'org_head_user_id',
                'dept_chair_user_id',
                'college_dean_user_id',
                'governance_snapshots',
            ]);
        });
    }
};
