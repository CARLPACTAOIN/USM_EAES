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
        Schema::table('users', function (Blueprint $table): void {
            $table->foreignUuid('department_id')
                ->nullable()
                ->after('college_id')
                ->constrained()
                ->nullOnDelete();
        });

        Schema::table('organization_memberships', function (Blueprint $table): void {
            $table->foreignUuid('department_id')
                ->nullable()
                ->after('college_id')
                ->constrained()
                ->nullOnDelete();

            $table->foreignUuid('school_year_id')
                ->nullable()
                ->after('academic_year')
                ->constrained()
                ->nullOnDelete();

            $table->index(['department_id', 'program_id'], 'organization_memberships_department_program_index');
            $table->index(['school_year_id', 'organization_id', 'status'], 'organization_memberships_school_year_org_status_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('organization_memberships', function (Blueprint $table): void {
            $table->dropIndex('organization_memberships_school_year_org_status_index');
            $table->dropIndex('organization_memberships_department_program_index');
            $table->dropForeign(['school_year_id']);
            $table->dropForeign(['department_id']);
            $table->dropColumn(['school_year_id', 'department_id']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign(['department_id']);
            $table->dropColumn('department_id');
        });
    }
};
