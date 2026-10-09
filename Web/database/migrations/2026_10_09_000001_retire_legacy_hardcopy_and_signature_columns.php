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
        Schema::table('events', function (Blueprint $table): void {
            $columnsToDrop = [];
            foreach (['hardcopy_submitted', 'hardcopy_submitted_at', 'head_organization_signed', 'adviser_signed'] as $column) {
                if (Schema::hasColumn('events', $column)) {
                    $columnsToDrop[] = $column;
                }
            }

            if ($columnsToDrop !== []) {
                $table->dropColumn($columnsToDrop);
            }
        });

        Schema::table('event_participation_scopes', function (Blueprint $table): void {
            $columnsToDrop = [];
            foreach (['hardcopy_submitted', 'org_head_signed', 'adviser_signed'] as $column) {
                if (Schema::hasColumn('event_participation_scopes', $column)) {
                    $columnsToDrop[] = $column;
                }
            }

            if ($columnsToDrop !== []) {
                $table->dropColumn($columnsToDrop);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            if (! Schema::hasColumn('events', 'hardcopy_submitted')) {
                $table->boolean('hardcopy_submitted')->default(false)->after('resolution_number');
            }
            if (! Schema::hasColumn('events', 'hardcopy_submitted_at')) {
                $table->timestamp('hardcopy_submitted_at')->nullable()->after('hardcopy_submitted');
            }
            if (! Schema::hasColumn('events', 'head_organization_signed')) {
                $table->boolean('head_organization_signed')->default(false)->after('hardcopy_submitted_at');
            }
            if (! Schema::hasColumn('events', 'adviser_signed')) {
                $table->boolean('adviser_signed')->default(false)->after('head_organization_signed');
            }
        });

        Schema::table('event_participation_scopes', function (Blueprint $table): void {
            if (! Schema::hasColumn('event_participation_scopes', 'hardcopy_submitted')) {
                $table->boolean('hardcopy_submitted')->default(false)->after('ppa_softcopy_original_name');
            }
            if (! Schema::hasColumn('event_participation_scopes', 'org_head_signed')) {
                $table->boolean('org_head_signed')->default(false)->after('hardcopy_submitted');
            }
            if (! Schema::hasColumn('event_participation_scopes', 'adviser_signed')) {
                $table->boolean('adviser_signed')->default(false)->after('org_head_signed');
            }
        });
    }
};
