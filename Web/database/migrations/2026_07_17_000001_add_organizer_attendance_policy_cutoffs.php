<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->integer('event_late_cutoff_min')->nullable()->after('general_competition_threshold_min');
        });

        Schema::table('event_participation_scopes', function (Blueprint $table): void {
            $table->integer('late_cutoff_min')->nullable()->after('analytics_rollup_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('event_participation_scopes', function (Blueprint $table): void {
            $table->dropColumn('late_cutoff_min');
        });

        Schema::table('events', function (Blueprint $table): void {
            $table->dropColumn('event_late_cutoff_min');
        });
    }
};
