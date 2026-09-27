<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'year_level')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->string('year_level', 20)->nullable()->after('program_code')->index();
            });
        }

        if (! Schema::hasColumn('organization_memberships', 'year_level')) {
            Schema::table('organization_memberships', function (Blueprint $table): void {
                $table->string('year_level', 20)->nullable()->after('program_id')->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('organization_memberships', 'year_level')) {
            Schema::table('organization_memberships', function (Blueprint $table): void {
                $table->dropColumn('year_level');
            });
        }

        if (Schema::hasColumn('users', 'year_level')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropColumn('year_level');
            });
        }
    }
};
