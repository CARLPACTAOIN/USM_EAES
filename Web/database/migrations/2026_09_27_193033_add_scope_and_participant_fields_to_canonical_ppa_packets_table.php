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
        Schema::table('canonical_ppa_packets', function (Blueprint $table) {
            $table->dropUnique(['event_id']);
            $table->foreignUuid('event_participation_scope_id')->nullable()->after('event_id')->constrained('event_participation_scopes')->cascadeOnDelete();
            $table->unsignedInteger('delegate_count')->nullable()->after('beneficiaries');
            $table->string('supporting_document_path')->nullable()->after('venue_confirmation_document_original_name');
            $table->string('supporting_document_original_name')->nullable()->after('supporting_document_path');
            $table->unique('event_participation_scope_id');
        });

        DB::statement('CREATE UNIQUE INDEX canonical_ppa_packets_organizer_event_unique ON canonical_ppa_packets (event_id) WHERE event_participation_scope_id IS NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS canonical_ppa_packets_organizer_event_unique');

        Schema::table('canonical_ppa_packets', function (Blueprint $table) {
            $table->dropUnique(['event_participation_scope_id']);
            $table->dropConstrainedForeignId('event_participation_scope_id');
            $table->dropColumn([
                'delegate_count',
                'supporting_document_path',
                'supporting_document_original_name',
            ]);
            $table->unique('event_id');
        });
    }
};
